import 'user.dart';

class Room {
  final int id;
  final String name;
  final String type; // 'direct' | 'group'
  final int memberCount;
  final String? lastMessage;
  final String? lastMessageAt;
  final List<User> members;

  Room({
    required this.id,
    required this.name,
    required this.type,
    this.memberCount = 0,
    this.lastMessage,
    this.lastMessageAt,
    this.members = const [],
  });

  bool get isDirect => type == 'direct';

  factory Room.fromJson(Map<String, dynamic> j) => Room(
        id: j['id'],
        name: j['name'] ?? '',
        type: j['type'] ?? 'group',
        memberCount: j['member_count'] ?? 0,
        lastMessage: j['last_message'],
        lastMessageAt: j['last_message_at'],
        members: (j['members'] as List<dynamic>? ?? [])
            .map((m) => User.fromJson(m))
            .toList(),
      );
}
