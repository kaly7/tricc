import 'dart:async';
import 'package:flutter_webrtc/flutter_webrtc.dart';
import 'ws_service.dart';

enum CallState { idle, calling, ringing, active }

class CallService {
  static final CallService _i = CallService._();
  factory CallService() => _i;
  CallService._();

  void Function()? onIncomingCall;
  void Function()? onCallStarted;
  void Function()? onCallEnded;

  RTCPeerConnection? _pc;
  MediaStream? _localStream;

  CallState _state = CallState.idle;
  String? _callId;
  int? _remoteUserId;
  String? _remoteUserName;
  bool _isCaller = false;
  bool _muted = false;
  bool _speakerOn = true;

  // ICE buffering: candidates csak setRemoteDescription UTÁN adhatók hozzá
  bool _remoteDescSet = false;
  Map<String, dynamic>? _pendingSdpOffer;
  final _pendingIce = <Map<String, dynamic>>[];

  final _stateCtrl = StreamController<CallState>.broadcast();
  Stream<CallState> get stateStream => _stateCtrl.stream;
  CallState get state => _state;
  String? get remoteUserName => _remoteUserName;
  bool get isMuted => _muted;
  bool get isSpeakerOn => _speakerOn;
  bool get isCaller => _isCaller;

  StreamSubscription? _wsSub;

  void init() {
    _wsSub?.cancel();
    _wsSub = WsService().events.listen((msg) {
      _onWsEvent(msg).catchError((_) {
        _endCallLocal();
        onCallEnded?.call();
      });
    });
  }

  void _setState(CallState s) {
    _state = s;
    _stateCtrl.add(s);
  }

  Future<void> _onWsEvent(Map<String, dynamic> msg) async {
    switch (msg['type'] as String?) {
      case 'call_initiated':
        _callId = msg['call_id'] as String?;
        break;
      case 'incoming_call':
        if (_state != CallState.idle) {
          WsService().sendJson({'type': 'call_reject', 'call_id': msg['call_id']});
          return;
        }
        _callId = msg['call_id'] as String?;
        _remoteUserId = msg['caller_id'] as int?;
        _remoteUserName = msg['caller_name'] as String?;
        _isCaller = false;
        _setState(CallState.ringing);
        onIncomingCall?.call();
        break;
      case 'call_accepted':
        if (_state == CallState.calling) {
          try {
            await _initWebRTC(isCaller: true);
            _setState(CallState.active);
          } catch (_) {
            _endCallLocal();
            onCallEnded?.call();
          }
        }
        break;
      case 'call_rejected':
      case 'call_cancelled':
      case 'call_ended':
      case 'call_timeout':
      case 'call_error':
        _endCallLocal();
        break;
      case 'sdp_offer':
        if (_pc == null) {
          _pendingSdpOffer = msg;
        } else {
          await _handleSdpOffer(msg);
        }
        break;
      case 'sdp_answer':
        if (_pc != null && !_remoteDescSet) {
          final sdp = msg['sdp'] as Map;
          await _pc!.setRemoteDescription(
            RTCSessionDescription(sdp['sdp'] as String, sdp['type'] as String),
          );
          _remoteDescSet = true;
          await _flushPendingIce();
        }
        break;
      case 'ice_candidate':
        // Puffer: setRemoteDescription előtt nem lehet addCandidate-et hívni
        if (_pc == null || !_remoteDescSet) {
          _pendingIce.add(msg);
        } else {
          await _handleIceCandidate(msg);
        }
        break;
    }
  }

  Future<void> _handleSdpOffer(Map<String, dynamic> msg) async {
    final sdp = msg['sdp'] as Map;
    await _pc!.setRemoteDescription(
      RTCSessionDescription(sdp['sdp'] as String, sdp['type'] as String),
    );
    _remoteDescSet = true;
    // ICE kandidátok kiadása most hogy remote desc be van állítva
    await _flushPendingIce();
    final answer = await _pc!.createAnswer();
    await _pc!.setLocalDescription(answer);
    WsService().sendJson({'type': 'sdp_answer', 'call_id': _callId, 'sdp': answer.toMap()});
  }

  Future<void> _flushPendingIce() async {
    final candidates = List<Map<String, dynamic>>.from(_pendingIce);
    _pendingIce.clear();
    for (final c in candidates) {
      await _handleIceCandidate(c);
    }
  }

  Future<void> _handleIceCandidate(Map<String, dynamic> msg) async {
    final c = msg['candidate'] as Map;
    final candidateStr = c['candidate'] as String?;
    if (candidateStr == null || candidateStr.isEmpty) return;
    await _pc!.addCandidate(RTCIceCandidate(
      candidateStr,
      c['sdpMid'] as String?,
      (c['sdpMLineIndex'] as num?)?.toInt(),
    ));
  }

  Future<void> startCall(int targetUserId, String targetName) async {
    if (_state != CallState.idle) return;
    _remoteUserId = targetUserId;
    _remoteUserName = targetName;
    _isCaller = true;
    _setState(CallState.calling);
    WsService().sendJson({'type': 'call_invite', 'target_user_id': targetUserId});
    onCallStarted?.call();
  }

  Future<void> acceptCall() async {
    if (_state != CallState.ringing) return;
    WsService().sendJson({'type': 'call_accept', 'call_id': _callId});
    try {
      await _initWebRTC(isCaller: false);
      _setState(CallState.active);
    } catch (_) {
      _endCallLocal();
      onCallEnded?.call();
    }
  }

  void rejectCall() {
    if (_state != CallState.ringing) return;
    WsService().sendJson({'type': 'call_reject', 'call_id': _callId});
    _cleanup();
    _setState(CallState.idle);
  }

  void hangUp() {
    if (_callId != null) {
      WsService().sendJson({'type': 'call_end', 'call_id': _callId});
    }
    _cleanup();
    _setState(CallState.idle);
    onCallEnded?.call();
  }

  void toggleMute() {
    _muted = !_muted;
    _localStream?.getAudioTracks().forEach((t) => t.enabled = !_muted);
    _stateCtrl.add(_state);
  }

  void toggleSpeaker() {
    _speakerOn = !_speakerOn;
    Helper.setSpeakerphoneOn(_speakerOn);
    _stateCtrl.add(_state);
  }

  Future<void> _initWebRTC({required bool isCaller}) async {
    _localStream = await navigator.mediaDevices.getUserMedia({
      'audio': true,
      'video': false,
    });

    _pc = await createPeerConnection({
      'iceServers': [
        {'urls': 'stun:stun.l.google.com:19302'},
        {'urls': 'stun:stun1.l.google.com:19302'},
        {'urls': 'stun:stun2.l.google.com:19302'},
      ],
      'sdpSemantics': 'unified-plan',
    });

    _localStream!.getTracks().forEach((track) {
      _pc!.addTrack(track, _localStream!);
    });

    _pc!.onIceCandidate = (candidate) {
      if (candidate.candidate != null && candidate.candidate!.isNotEmpty && _callId != null) {
        WsService().sendJson({
          'type': 'ice_candidate',
          'call_id': _callId,
          'candidate': {
            'candidate': candidate.candidate,
            'sdpMid': candidate.sdpMid,
            'sdpMLineIndex': candidate.sdpMLineIndex,
          },
        });
      }
    };

    _pc!.onConnectionState = (state) {
      if (state == RTCPeerConnectionState.RTCPeerConnectionStateConnected) {
        Helper.setSpeakerphoneOn(_speakerOn);
        if (_state != CallState.active) _setState(CallState.active);
      } else if (state == RTCPeerConnectionState.RTCPeerConnectionStateFailed) {
        _endCallLocal();
        onCallEnded?.call();
      }
      // disconnected = átmeneti állapot ICE tárgyalás közben, nem zárjuk le
    };

    if (isCaller) {
      final offer = await _pc!.createOffer();
      await _pc!.setLocalDescription(offer);
      WsService().sendJson({
        'type': 'sdp_offer',
        'call_id': _callId,
        'sdp': offer.toMap(),
      });
    } else {
      // Ha sdp_offer már megérkezett mielőtt _pc létrejött
      if (_pendingSdpOffer != null) {
        await _handleSdpOffer(_pendingSdpOffer!);
        _pendingSdpOffer = null;
      }
      // Ha sdp_offer megérkezett és _handleSdpOffer már lefutott (concurrent),
      // a _pendingIce ott lett kiadva — itt már üres lesz
    }
  }

  void _endCallLocal() {
    _cleanup();
    _setState(CallState.idle);
  }

  void _cleanup() {
    _localStream?.dispose();
    _localStream = null;
    _pc?.dispose();
    _pc = null;
    _callId = null;
    _remoteUserId = null;
    _remoteUserName = null;
    _isCaller = false;
    _muted = false;
    _speakerOn = true;
    _remoteDescSet = false;
    _pendingSdpOffer = null;
    _pendingIce.clear();
  }
}
