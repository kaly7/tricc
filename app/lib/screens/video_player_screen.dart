import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/io_client.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:video_player/video_player.dart';

class VideoPlayerScreen extends StatefulWidget {
  final String url;
  final String title;
  const VideoPlayerScreen({super.key, required this.url, required this.title});

  @override
  State<VideoPlayerScreen> createState() => _VideoPlayerScreenState();
}

class _VideoPlayerScreenState extends State<VideoPlayerScreen> {
  VideoPlayerController? _ctrl;
  bool _initialized = false;
  bool _showControls = true;
  String? _error;
  double? _downloadProgress;

  static http.Client _buildClient() {
    final inner = HttpClient()..badCertificateCallback = (_, __, ___) => true;
    return IOClient(inner);
  }

  @override
  void initState() {
    super.initState();
    SystemChrome.setPreferredOrientations([
      DeviceOrientation.portraitUp,
      DeviceOrientation.landscapeLeft,
      DeviceOrientation.landscapeRight,
    ]);
    _downloadAndPlay();
  }

  Future<void> _downloadAndPlay() async {
    try {
      final dir = await getTemporaryDirectory();
      final cacheFile = File('${dir.path}/babl42_${widget.url.split('/').last}');

      if (!await cacheFile.exists()) {
        final client = _buildClient();
        final req = http.Request('GET', Uri.parse(widget.url));
        final res = await client.send(req);
        if (res.statusCode != 200 && res.statusCode != 206) {
          throw Exception('HTTP ${res.statusCode}');
        }
        final total = res.contentLength;
        int received = 0;
        final sink = cacheFile.openWrite();
        await for (final chunk in res.stream) {
          sink.add(chunk);
          received += chunk.length;
          if (total != null && total > 0 && mounted) {
            setState(() => _downloadProgress = received / total);
          }
        }
        await sink.close();
        client.close();
      }

      if (!mounted) return;

      final ctrl = VideoPlayerController.file(cacheFile);
      ctrl.addListener(() { if (mounted) setState(() {}); });
      await ctrl.initialize();

      if (!mounted) return;
      setState(() {
        _ctrl = ctrl;
        _initialized = true;
        _downloadProgress = null;
      });
      ctrl.play();
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _downloadProgress = null; });
    }
  }

  @override
  void dispose() {
    SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]);
    _ctrl?.dispose();
    super.dispose();
  }

  void _toggleControls() => setState(() => _showControls = !_showControls);

  void _togglePlay() {
    if (_ctrl == null) return;
    _ctrl!.value.isPlaying ? _ctrl!.pause() : _ctrl!.play();
  }

  String _formatDuration(Duration d) {
    final m = d.inMinutes.remainder(60).toString().padLeft(2, '0');
    final s = d.inSeconds.remainder(60).toString().padLeft(2, '0');
    return '$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: GestureDetector(
        onTap: _toggleControls,
        child: Stack(
          alignment: Alignment.center,
          children: [
            if (_error != null)
              Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.error_outline, color: Colors.white54, size: 48),
                      const SizedBox(height: 12),
                      Text(_error!, style: const TextStyle(color: Colors.white54, fontSize: 13), textAlign: TextAlign.center),
                    ],
                  ),
                ),
              )
            else if (_initialized && _ctrl != null)
              Center(
                child: AspectRatio(
                  aspectRatio: _ctrl!.value.aspectRatio,
                  child: VideoPlayer(_ctrl!),
                ),
              )
            else
              Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (_downloadProgress != null) ...[
                      SizedBox(
                        width: 200,
                        child: LinearProgressIndicator(
                          value: _downloadProgress,
                          backgroundColor: Colors.white24,
                          valueColor: const AlwaysStoppedAnimation(Colors.white),
                        ),
                      ),
                      const SizedBox(height: 12),
                      Text(
                        '${(_downloadProgress! * 100).toStringAsFixed(0)}%',
                        style: const TextStyle(color: Colors.white54, fontSize: 13),
                      ),
                    ] else
                      const CircularProgressIndicator(color: Colors.white),
                  ],
                ),
              ),

            if (_showControls) ...[
              Positioned(
                top: 0, left: 0, right: 0,
                child: SafeArea(
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: const BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment.topCenter,
                        end: Alignment.bottomCenter,
                        colors: [Colors.black54, Colors.transparent],
                      ),
                    ),
                    child: Row(
                      children: [
                        IconButton(
                          icon: const Icon(Icons.arrow_back, color: Colors.white),
                          onPressed: () => Navigator.pop(context),
                        ),
                        Expanded(
                          child: Text(
                            widget.title,
                            style: const TextStyle(color: Colors.white, fontSize: 14),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              if (_initialized && _ctrl != null)
                Positioned(
                  bottom: 0, left: 0, right: 0,
                  child: SafeArea(
                    child: Container(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                      decoration: const BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.bottomCenter,
                          end: Alignment.topCenter,
                          colors: [Colors.black54, Colors.transparent],
                        ),
                      ),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          VideoProgressIndicator(
                            _ctrl!,
                            allowScrubbing: true,
                            colors: const VideoProgressColors(
                              playedColor: Colors.white,
                              bufferedColor: Colors.white38,
                              backgroundColor: Colors.white24,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Row(
                            children: [
                              IconButton(
                                icon: Icon(
                                  _ctrl!.value.isPlaying ? Icons.pause : Icons.play_arrow,
                                  color: Colors.white, size: 28,
                                ),
                                onPressed: _togglePlay,
                              ),
                              Text(
                                _formatDuration(_ctrl!.value.position),
                                style: const TextStyle(color: Colors.white, fontSize: 12),
                              ),
                              const Text(' / ', style: TextStyle(color: Colors.white54, fontSize: 12)),
                              Text(
                                _formatDuration(_ctrl!.value.duration),
                                style: const TextStyle(color: Colors.white54, fontSize: 12),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
