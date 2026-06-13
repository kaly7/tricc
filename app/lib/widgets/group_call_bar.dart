import 'package:flutter/material.dart';
import '../services/group_call_service.dart';
import '../screens/group_call_screen.dart';

class GroupCallBar extends StatelessWidget {
  const GroupCallBar({super.key});

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: GroupCallService(),
      builder: (context, _) {
        final svc = GroupCallService();
        if (!svc.isActive && !svc.isConnecting) return const SizedBox.shrink();

        final participantCount = (svc.room?.remoteParticipants.length ?? 0) + 1;

        return GestureDetector(
          onTap: () {
            if (svc.chatRoomId == null) return;
            Navigator.push(
              context,
              MaterialPageRoute(
                builder: (_) => GroupCallScreen(
                  roomId: svc.chatRoomId!,
                  roomName: svc.chatRoomName,
                ),
              ),
            );
          },
          child: Container(
            height: 48,
            decoration: const BoxDecoration(
              color: Color(0xFF1A1A2E),
              border: Border(top: BorderSide(color: Color(0xFF2E2E4E), width: 1)),
            ),
            child: Row(
              children: [
                const SizedBox(width: 12),
                const Icon(Icons.headset, color: Color(0xFF4A9EFF), size: 20),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        svc.isConnecting ? 'Csatlakozás...' : svc.chatRoomName,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                        ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      if (svc.isActive)
                        Text(
                          '$participantCount résztvevő',
                          style: const TextStyle(color: Colors.white54, fontSize: 11),
                        ),
                    ],
                  ),
                ),
                if (svc.isActive) ...[
                  IconButton(
                    icon: Icon(
                      svc.isMuted ? Icons.mic_off : Icons.mic,
                      color: svc.isMuted ? Colors.red : Colors.white70,
                      size: 20,
                    ),
                    onPressed: svc.toggleMute,
                    tooltip: svc.isMuted ? 'Mikrofon bekapcsolása' : 'Némítás',
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
                  ),
                  IconButton(
                    icon: const Icon(Icons.call_end, color: Colors.red, size: 20),
                    onPressed: svc.leave,
                    tooltip: 'Hívás befejezése',
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
                  ),
                ],
                const Icon(Icons.chevron_right, color: Colors.white38, size: 20),
                const SizedBox(width: 4),
              ],
            ),
          ),
        );
      },
    );
  }
}
