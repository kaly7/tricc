import 'package:flutter/foundation.dart';
import 'package:livekit_client/livekit_client.dart';
import 'api_service.dart';

class GroupCallService extends ChangeNotifier {
  static final GroupCallService _i = GroupCallService._();
  factory GroupCallService() => _i;
  GroupCallService._();

  Room? _room;
  bool _connecting = false;
  String? _error;

  Room? get room => _room;
  bool get isConnecting => _connecting;
  bool get isActive => _room?.connectionState == ConnectionState.connected;
  String? get error => _error;
  bool get isMuted => !(_room?.localParticipant?.isMicrophoneEnabled() ?? true);

  Future<void> join(int roomId) async {
    if (_connecting || isActive) return;
    _connecting = true;
    _error = null;
    notifyListeners();
    try {
      final data = await ApiService().getLiveKitToken(roomId);
      final r = Room();
      r.addListener(notifyListeners);
      await r.connect(
        data['url'] as String,
        data['token'] as String,
        roomOptions: const RoomOptions(adaptiveStream: true, dynacast: true),
      );
      await r.localParticipant?.setMicrophoneEnabled(true);
      _room = r;
    } catch (e) {
      _error = e.toString();
    } finally {
      _connecting = false;
      notifyListeners();
    }
  }

  Future<void> leave() async {
    _room?.removeListener(notifyListeners);
    await _room?.disconnect();
    _room = null;
    _error = null;
    notifyListeners();
  }

  Future<void> toggleMute() async {
    final enabled = _room?.localParticipant?.isMicrophoneEnabled() ?? true;
    await _room?.localParticipant?.setMicrophoneEnabled(!enabled);
    notifyListeners();
  }
}
