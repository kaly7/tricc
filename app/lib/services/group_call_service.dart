import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart';
import 'package:livekit_client/livekit_client.dart';
import 'api_service.dart';

const _proximityChannel = MethodChannel('com.rv42.babl42/proximity');

enum AudioOutput { earpiece, speaker, bluetooth }

class GroupCallService extends ChangeNotifier {
  static final GroupCallService _i = GroupCallService._();
  factory GroupCallService() => _i;
  GroupCallService._();

  Room? _room;
  bool _connecting = false;
  String? _error;
  int? _chatRoomId;
  String _chatRoomName = '';
  final Map<int, String> _activeCallRooms = {};

  AudioOutput _audioOutput = AudioOutput.earpiece;
  List<MediaDeviceInfo> _audioDevices = [];

  AudioOutput get audioOutput => _audioOutput;
  List<MediaDeviceInfo> get audioDevices => _audioDevices;

  Room? get room => _room;
  bool get isConnecting => _connecting;
  bool get isActive => _room?.connectionState == ConnectionState.connected;
  String? get error => _error;
  bool get isMuted => !(_room?.localParticipant?.isMicrophoneEnabled() ?? true);
  int? get chatRoomId => _chatRoomId;
  String get chatRoomName => _chatRoomName;

  bool isRoomCallActive(int roomId) =>
      _activeCallRooms.containsKey(roomId) || (isActive && _chatRoomId == roomId);

  void markRoomCallActive(int roomId, String roomName) {
    _activeCallRooms[roomId] = roomName;
    notifyListeners();
  }

  void markRoomCallInactive(int roomId) {
    _activeCallRooms.remove(roomId);
    notifyListeners();
  }

  Future<void> join(int roomId, String roomName) async {
    if (_connecting || isActive) return;
    _chatRoomId = roomId;
    _chatRoomName = roomName;
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
      _activeCallRooms[roomId] = roomName;
      try { await ApiService().notifyCallStarted(roomId); } catch (_) {}
      reloadAudioDevices();
      try { await _proximityChannel.invokeMethod('enable'); } catch (_) {}
    } catch (e) {
      _error = e.toString();
      _chatRoomId = null;
      _chatRoomName = '';
    } finally {
      _connecting = false;
      notifyListeners();
    }
  }

  Future<void> reloadAudioDevices() async {
    try {
      _audioDevices = await Helper.audiooutputs;
    } catch (_) {
      _audioDevices = [];
    }
    notifyListeners();
  }

  Future<void> setAudioOutput(AudioOutput output, {String? deviceId}) async {
    try {
      switch (output) {
        case AudioOutput.earpiece:
          await Helper.setSpeakerphoneOn(false);
        case AudioOutput.speaker:
          await Helper.setSpeakerphoneOn(true);
        case AudioOutput.bluetooth:
          if (deviceId != null) {
            await Helper.selectAudioOutput(deviceId);
          } else {
            await Helper.setSpeakerphoneOnButPreferBluetooth();
          }
      }
      _audioOutput = output;
      notifyListeners();
    } catch (_) {}
  }

  Future<void> leave() async {
    final leavingRoomId = _chatRoomId;
    _room?.removeListener(notifyListeners);
    await _room?.disconnect();
    _room = null;
    _error = null;
    _chatRoomId = null;
    _chatRoomName = '';
    if (leavingRoomId != null) _activeCallRooms.remove(leavingRoomId);
    _audioOutput = AudioOutput.earpiece;
    _audioDevices = [];
    try { await _proximityChannel.invokeMethod('disable'); } catch (_) {}
    notifyListeners();
  }

  Future<void> toggleMute() async {
    final enabled = _room?.localParticipant?.isMicrophoneEnabled() ?? true;
    await _room?.localParticipant?.setMicrophoneEnabled(!enabled);
    notifyListeners();
  }
}
