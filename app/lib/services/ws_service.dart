import 'dart:async';
import 'dart:convert';
import 'package:web_socket_channel/web_socket_channel.dart';
import 'auth_service.dart';
import 'settings_service.dart';

enum WsState { connected, connecting, disconnected }

class WsService {
  static final WsService _i = WsService._();
  factory WsService() => _i;
  WsService._();

  static String get _url => 'wss://${SettingsService().serverHost}/ws';
  static const Duration _pingInterval = Duration(seconds: 30);
  static const Duration _pongTimeout  = Duration(seconds: 10);

  WebSocketChannel? _channel;
  Timer? _reconnectTimer;
  Timer? _pingTimer;
  Timer? _pongTimer;
  bool _intentionalDisconnect = false;
  final Set<int> _joinedRooms = {};

  final _controller = StreamController<Map<String, dynamic>>.broadcast();
  Stream<Map<String, dynamic>> get events => _controller.stream;

  final _stateController = StreamController<WsState>.broadcast();
  Stream<WsState> get stateStream => _stateController.stream;
  WsState _state = WsState.disconnected;
  WsState get state => _state;

  final Set<int> _onlineUsers = {};
  Set<int> get onlineUsers => Set.unmodifiable(_onlineUsers);

  bool get isConnected => _state == WsState.connected;

  void _setState(WsState s) {
    if (_state == s) return;
    _state = s;
    _stateController.add(s);
  }

  Future<void> connect() async {
    if (_state == WsState.connected) return;
    _intentionalDisconnect = false;
    _setState(WsState.connecting);
    await _doConnect();
  }

  Future<void> _doConnect() async {
    final token = AuthService().token;
    if (token == null) return;

    try {
      _channel = WebSocketChannel.connect(Uri.parse(_url));
      _channel!.stream.listen(
        (data) {
          try {
            final msg = jsonDecode(data as String) as Map<String, dynamic>;
            _handleIncoming(msg);
            _controller.add(msg);
          } catch (_) {}
        },
        onDone: _onDisconnected,
        onError: (_) => _onDisconnected(),
      );
      _send({'type': 'auth', 'token': token});
      for (final roomId in _joinedRooms) {
        _send({'type': 'join', 'room_id': roomId});
      }
    } catch (_) {
      _setState(WsState.disconnected);
      _scheduleReconnect();
    }
  }

  void _handleIncoming(Map<String, dynamic> msg) {
    if (msg['type'] == 'auth_ok') {
      _setState(WsState.connected);
      _startPingTimer();
    }

    if (msg['type'] == 'pong') {
      _pongTimer?.cancel();
      return;
    }

    if (msg['type'] == 'presence') {
      final userId = msg['user_id'] as int?;
      final online = msg['online'] as bool? ?? false;
      if (userId != null) {
        if (online) _onlineUsers.add(userId);
        else _onlineUsers.remove(userId);
      }
      return;
    }

    if (msg['type'] == 'presence_list') {
      final ids = (msg['online_user_ids'] as List?)?.map((e) => e as int).toList() ?? [];
      _onlineUsers.addAll(ids);
      return;
    }

    if (msg['type'] == 'message') {
      final message = msg['message'] as Map<String, dynamic>?;
      if (message == null) return;
      final senderId = message['user_id'] as int?;
      final myId = AuthService().userId;
      if (senderId == null || senderId == myId) return;
      final messageId = message['id'] as int?;
      final roomId = msg['room_id'] as int?;
      if (messageId == null || roomId == null) return;
      _send({'type': 'delivered', 'message_id': messageId, 'room_id': roomId});
    }
  }

  void _startPingTimer() {
    _pingTimer?.cancel();
    _pingTimer = Timer.periodic(_pingInterval, (_) => _sendPing());
  }

  void _sendPing() {
    _send({'type': 'ping'});
    _pongTimer?.cancel();
    // Ha 10 másodpercen belül nem jön pong, a kapcsolat halott → reconnect
    _pongTimer = Timer(_pongTimeout, () {
      _onDisconnected();
    });
  }

  void _stopTimers() {
    _pingTimer?.cancel();
    _pongTimer?.cancel();
    _pingTimer = null;
    _pongTimer = null;
  }

  void _onDisconnected() {
    _stopTimers();
    _channel = null;
    _setState(WsState.disconnected);
    if (!_intentionalDisconnect) _scheduleReconnect();
  }

  void _scheduleReconnect() {
    _reconnectTimer?.cancel();
    _setState(WsState.connecting);
    _reconnectTimer = Timer(const Duration(seconds: 5), _doConnect);
  }

  void join(int roomId) {
    _joinedRooms.add(roomId);
    _send({'type': 'join', 'room_id': roomId});
  }

  void leave(int roomId) {
    _joinedRooms.remove(roomId);
    _send({'type': 'leave', 'room_id': roomId});
  }

  void sendTyping(int roomId, bool typing) =>
      _send({'type': 'typing', 'room_id': roomId, 'typing': typing});

  void sendJson(Map<String, dynamic> msg) => _send(msg);

  void _send(Map<String, dynamic> msg) {
    try {
      _channel?.sink.add(jsonEncode(msg));
    } catch (_) {}
  }

  void disconnect() {
    _intentionalDisconnect = true;
    _reconnectTimer?.cancel();
    _stopTimers();
    _onlineUsers.clear();
    _channel?.sink.close();
    _channel = null;
    _setState(WsState.disconnected);
  }
}
