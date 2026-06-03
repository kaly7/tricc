import 'dart:async';
import 'dart:convert';
import 'package:web_socket_channel/web_socket_channel.dart';
import 'auth_service.dart';

class WsService {
  static final WsService _i = WsService._();
  factory WsService() => _i;
  WsService._();

  static const String _url = 'ws://192.168.16.22:9454';

  WebSocketChannel? _channel;
  Timer? _reconnectTimer;
  bool _intentionalDisconnect = false;

  final _controller = StreamController<Map<String, dynamic>>.broadcast();
  Stream<Map<String, dynamic>> get events => _controller.stream;

  bool get isConnected => _channel != null;

  Future<void> connect() async {
    _intentionalDisconnect = false;
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
            _controller.add(msg);
          } catch (_) {}
        },
        onDone: _onDisconnected,
        onError: (_) => _onDisconnected(),
      );
      _send({'type': 'auth', 'token': token});
    } catch (_) {
      _scheduleReconnect();
    }
  }

  void _onDisconnected() {
    _channel = null;
    if (!_intentionalDisconnect) _scheduleReconnect();
  }

  void _scheduleReconnect() {
    _reconnectTimer?.cancel();
    _reconnectTimer = Timer(const Duration(seconds: 5), _doConnect);
  }

  void join(int roomId) => _send({'type': 'join', 'room_id': roomId});
  void leave(int roomId) => _send({'type': 'leave', 'room_id': roomId});
  void sendTyping(int roomId, bool typing) =>
      _send({'type': 'typing', 'room_id': roomId, 'typing': typing});

  void _send(Map<String, dynamic> msg) {
    try {
      _channel?.sink.add(jsonEncode(msg));
    } catch (_) {}
  }

  void disconnect() {
    _intentionalDisconnect = true;
    _reconnectTimer?.cancel();
    _channel?.sink.close();
    _channel = null;
  }
}
