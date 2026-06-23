import 'package:receive_sharing_intent/receive_sharing_intent.dart';

export 'package:receive_sharing_intent/receive_sharing_intent.dart'
    show SharedMediaFile, SharedMediaType;

class ShareService {
  static final ShareService _i = ShareService._();
  factory ShareService() => _i;
  ShareService._();

  Future<List<SharedMediaFile>> getInitialMedia() =>
      ReceiveSharingIntent.instance.getInitialMedia();

  Stream<List<SharedMediaFile>> get mediaStream =>
      ReceiveSharingIntent.instance.getMediaStream();

  void reset() => ReceiveSharingIntent.instance.reset();
}
