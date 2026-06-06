import 'dart:async';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../services/ws_service.dart';

// Kis pötty a nevek mellé: zöld = online, szürke = offline
class PresenceDot extends StatelessWidget {
  final int? userId;
  const PresenceDot({super.key, this.userId});

  @override
  Widget build(BuildContext context) {
    final online = userId != null && WsService().onlineUsers.contains(userId);
    final color = online ? const Color(0xFF4CAF50) : Colors.grey.shade400;
    return Container(
      width: 10,
      height: 10,
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        boxShadow: [BoxShadow(color: color.withOpacity(0.5), blurRadius: 4, spreadRadius: 1)],
      ),
    );
  }
}

// Státusz pötty az AppBar-ba (actions között) — statikus, nincs animáció
class WsDot extends StatefulWidget {
  const WsDot({super.key});

  @override
  State<WsDot> createState() => _WsDotState();
}

class _WsDotState extends State<WsDot> {
  WsState _state = WsService().state;
  StreamSubscription<WsState>? _sub;

  @override
  void initState() {
    super.initState();
    _sub = WsService().stateStream.listen((s) {
      if (mounted) setState(() => _state = s);
    });
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final Color color = switch (_state) {
      WsState.connected    => const Color(0xFF4CAF50),
      WsState.connecting   => Colors.amber,
      WsState.disconnected => Colors.red,
    };
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: Center(
        child: Container(
          width: 10,
          height: 10,
          decoration: BoxDecoration(
            color: color,
            shape: BoxShape.circle,
            boxShadow: [BoxShadow(color: color.withOpacity(0.6), blurRadius: 5, spreadRadius: 1)],
          ),
        ),
      ),
    );
  }
}

// Megosztott avatar teljes képernyős megjelenítő dialog
void showAvatarDialog(BuildContext context, String name, String? avatarUrl) {
  const serverBase = 'https://192.168.16.22:9456';
  showDialog(
    context: context,
    barrierColor: Colors.black87,
    builder: (_) => GestureDetector(
      onTap: () => Navigator.pop(context),
      child: Dialog(
        backgroundColor: Colors.transparent,
        elevation: 0,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            avatarUrl != null
                ? CircleAvatar(
                    radius: 80,
                    backgroundImage: CachedNetworkImageProvider('$serverBase$avatarUrl'),
                  )
                : CircleAvatar(
                    radius: 80,
                    backgroundColor: const Color(0xFF1976D2),
                    child: Text(
                      name.isNotEmpty ? name[0].toUpperCase() : '?',
                      style: const TextStyle(fontSize: 64, color: Colors.white, fontWeight: FontWeight.bold),
                    ),
                  ),
            const SizedBox(height: 14),
            Text(name, style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
          ],
        ),
      ),
    ),
  );
}
