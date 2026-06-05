import 'package:flutter/material.dart';
import '../services/ws_service.dart';

// Kis pötty a nevek mellé: zöld = online, szürke = offline
class PresenceDot extends StatelessWidget {
  final int? userId;
  const PresenceDot({super.key, this.userId});

  @override
  Widget build(BuildContext context) {
    final online = userId != null && WsService().onlineUsers.contains(userId);
    return Container(
      width: 8,
      height: 8,
      decoration: BoxDecoration(
        color: online ? const Color(0xFF4CAF50) : Colors.grey.shade400,
        shape: BoxShape.circle,
      ),
    );
  }
}

// Pulzáló státusz pötty az AppBar-ba (actions között)
class WsDot extends StatefulWidget {
  const WsDot({super.key});

  @override
  State<WsDot> createState() => _WsDotState();
}

class _WsDotState extends State<WsDot> with SingleTickerProviderStateMixin {
  late AnimationController _pulse;
  late Animation<double> _scale;
  WsState _state = WsService().state;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 800))
      ..repeat(reverse: true);
    _scale = Tween<double>(begin: 0.7, end: 1.0).animate(
      CurvedAnimation(parent: _pulse, curve: Curves.easeInOut),
    );
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
    final Color color;
    final bool pulsing;
    switch (_state) {
      case WsState.connected:
        color = const Color(0xFF4CAF50);
        pulsing = false;
      case WsState.connecting:
        color = Colors.amber;
        pulsing = true;
      case WsState.disconnected:
        color = Colors.red;
        pulsing = true;
    }

    final dot = Container(
      width: 10,
      height: 10,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        boxShadow: [BoxShadow(color: color.withOpacity(0.5), blurRadius: 4, spreadRadius: 1)],
      ),
    );

    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: Center(
        child: pulsing
            ? ScaleTransition(scale: _scale, child: dot)
            : dot,
      ),
    );
  }
}
