import 'package:flutter/material.dart';
import '../services/ws_service.dart';

class WsStatusBar extends StatefulWidget {
  const WsStatusBar({super.key});

  @override
  State<WsStatusBar> createState() => _WsStatusBarState();
}

class _WsStatusBarState extends State<WsStatusBar> with SingleTickerProviderStateMixin {
  late AnimationController _pulse;
  late Animation<double> _opacity;
  WsState _state = WsService().state;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))
      ..repeat(reverse: true);
    _opacity = Tween<double>(begin: 0.5, end: 1.0).animate(_pulse);
    WsService().stateStream.listen((s) {
      if (mounted) setState(() => _state = s);
    });
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_state == WsState.connected) return const SizedBox.shrink();

    final color = _state == WsState.connecting ? Colors.amber : Colors.red;
    final label = _state == WsState.connecting ? 'Csatlakozás...' : 'Nincs kapcsolat';

    return FadeTransition(
      opacity: _opacity,
      child: Container(
        width: double.infinity,
        color: color,
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Text(
          label,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 11, color: Colors.white, fontWeight: FontWeight.w600),
        ),
      ),
    );
  }
}
