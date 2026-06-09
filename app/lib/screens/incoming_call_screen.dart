import 'dart:async';
import 'package:flutter/material.dart';
import '../services/call_service.dart';
import 'active_call_screen.dart';

class IncomingCallScreen extends StatefulWidget {
  const IncomingCallScreen({super.key});

  @override
  State<IncomingCallScreen> createState() => _IncomingCallScreenState();
}

class _IncomingCallScreenState extends State<IncomingCallScreen> {
  StreamSubscription? _sub;
  bool _accepting = false;

  @override
  void initState() {
    super.initState();
    _sub = CallService().stateStream.listen((state) {
      if (state == CallState.idle && mounted) {
        Navigator.of(context).pop();
      } else if (state == CallState.active && mounted && !_accepting) {
        Navigator.of(context).pushReplacement(
          MaterialPageRoute(builder: (_) => const ActiveCallScreen()),
        );
      }
    });
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }

  Future<void> _accept() async {
    setState(() => _accepting = true);
    await CallService().acceptCall();
    if (mounted) {
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const ActiveCallScreen()),
      );
    }
  }

  void _reject() {
    CallService().rejectCall();
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final name = CallService().remoteUserName ?? 'Ismeretlen';
    return Scaffold(
      backgroundColor: const Color(0xFF1A1A2E),
      body: SafeArea(
        child: Column(
          children: [
            const Spacer(),
            const Icon(Icons.call_received, size: 64, color: Colors.white54),
            const SizedBox(height: 16),
            const Text('Bejövő hívás', style: TextStyle(color: Colors.white54, fontSize: 16)),
            const SizedBox(height: 12),
            Text(
              name,
              style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.bold),
              textAlign: TextAlign.center,
            ),
            const Spacer(),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 60, vertical: 48),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  _CallButton(
                    icon: Icons.call_end,
                    color: Colors.red,
                    label: 'Elutasítás',
                    onTap: _reject,
                  ),
                  _CallButton(
                    icon: Icons.call,
                    color: Colors.green,
                    label: 'Fogadás',
                    onTap: _accepting ? null : _accept,
                    loading: _accepting,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _CallButton extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String label;
  final VoidCallback? onTap;
  final bool loading;

  const _CallButton({
    required this.icon,
    required this.color,
    required this.label,
    this.onTap,
    this.loading = false,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        GestureDetector(
          onTap: onTap,
          child: Container(
            width: 72,
            height: 72,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
            child: loading
                ? const Center(child: SizedBox(width: 28, height: 28, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2)))
                : Icon(icon, color: Colors.white, size: 32),
          ),
        ),
        const SizedBox(height: 8),
        Text(label, style: const TextStyle(color: Colors.white70, fontSize: 13)),
      ],
    );
  }
}
