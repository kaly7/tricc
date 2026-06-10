import 'dart:async';
import 'package:flutter/material.dart';
import 'package:wakelock_plus/wakelock_plus.dart';
import '../services/call_service.dart';

class ActiveCallScreen extends StatefulWidget {
  const ActiveCallScreen({super.key});

  @override
  State<ActiveCallScreen> createState() => _ActiveCallScreenState();
}

class _ActiveCallScreenState extends State<ActiveCallScreen> {
  StreamSubscription? _callSub;
  Timer? _timer;
  int _seconds = 0;
  bool _connected = false;

  @override
  void initState() {
    super.initState();
    WakelockPlus.enable();
    _connected = CallService().state == CallState.active;
    if (_connected) _startTimer();

    _callSub = CallService().stateStream.listen((state) {
      if (!mounted) return;
      if (state == CallState.idle) {
        Navigator.of(context).pop();
        return;
      }
      if (state == CallState.active && !_connected) {
        setState(() => _connected = true);
        _startTimer();
      }
      setState(() {});
    });
  }

  void _startTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() => _seconds++);
    });
  }

  @override
  void dispose() {
    WakelockPlus.disable();
    _callSub?.cancel();
    _timer?.cancel();
    super.dispose();
  }

  String get _duration {
    final m = (_seconds ~/ 60).toString().padLeft(2, '0');
    final s = (_seconds % 60).toString().padLeft(2, '0');
    return '$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    final svc = CallService();
    final name = svc.remoteUserName ?? 'Ismeretlen';

    return Scaffold(
      backgroundColor: const Color(0xFF1A1A2E),
      body: SafeArea(
        child: Column(
          children: [
            const Spacer(),
            const Icon(Icons.account_circle, size: 96, color: Colors.white24),
            const SizedBox(height: 16),
            Text(
              name,
              style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            Text(
              _connected ? _duration : 'Csatlakozás…',
              style: const TextStyle(color: Colors.white54, fontSize: 16),
            ),
            const Spacer(),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 16),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                children: [
                  _ToggleButton(
                    icon: svc.isMuted ? Icons.mic_off : Icons.mic,
                    label: svc.isMuted ? 'Némítva' : 'Mikrofon',
                    active: svc.isMuted,
                    onTap: () { svc.toggleMute(); setState(() {}); },
                  ),
                  _ToggleButton(
                    icon: svc.isSpeakerOn ? Icons.volume_up : Icons.hearing,
                    label: svc.isSpeakerOn ? 'Hangszóró' : 'Fülhallgató',
                    active: svc.isSpeakerOn,
                    onTap: () { svc.toggleSpeaker(); setState(() {}); },
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.only(bottom: 48),
              child: Column(
                children: [
                  GestureDetector(
                    onTap: () => svc.hangUp(),
                    child: Container(
                      width: 72,
                      height: 72,
                      decoration: const BoxDecoration(color: Colors.red, shape: BoxShape.circle),
                      child: const Icon(Icons.call_end, color: Colors.white, size: 32),
                    ),
                  ),
                  const SizedBox(height: 8),
                  const Text('Lerakás', style: TextStyle(color: Colors.white70, fontSize: 13)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _ToggleButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final bool active;
  final VoidCallback onTap;

  const _ToggleButton({
    required this.icon,
    required this.label,
    required this.active,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 60,
            height: 60,
            decoration: BoxDecoration(
              color: active ? Colors.white24 : Colors.white12,
              shape: BoxShape.circle,
            ),
            child: Icon(icon, color: Colors.white, size: 26),
          ),
          const SizedBox(height: 6),
          Text(label, style: const TextStyle(color: Colors.white70, fontSize: 12)),
        ],
      ),
    );
  }
}
