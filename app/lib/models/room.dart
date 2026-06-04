import 'user.dart';
import 'message.dart';

class Room {
  final int id;
  final String name;
  final String type; // 'direct' | 'group'
  final int memberCount;
  final String? lastMessage;
  final String? lastMessageAt;
  final List<User> members;
  final User? otherUser;
  final Message? pinnedMessage;
  final int unreadCount;
  final int? deleteRequestedBy;

  Room({
    required this.id,
    required this.name,
    required this.type,
    this.memberCount = 0,
    this.lastMessage,
    this.lastMessageAt,
    this.members = const [],
    this.otherUser,
    this.pinnedMessage,
    this.unreadCount = 0,
    this.deleteRequestedBy,
  });

  bool get isDirect => type == 'direct';

  String displayName(int myUserId) {
    if (isDirect) {
      if (otherUser != null) return otherUser!.name;
      final other = members.where((m) => m.id != myUserId).firstOrNull;
      return other?.name ?? name;
    }
    return name.isNotEmpty ? name : 'Névtelen csoport';
  }

  factory Room.fromJson(Map<String, dynamic> j) => Room(
        id: j['id'],
        name: j['name'] ?? '',
        type: j['type'] ?? 'group',
        memberCount: j['member_count'] ?? 0,
        lastMessage: j['last_message'],
        lastMessageAt: j['last_message_at'],
        members: (j['members'] as List<dynamic>? ?? [])
            .map((m) => User.fromJson(m as Map<String, dynamic>))
            .toList(),
        otherUser: j['other_user'] != null ? User.fromJson(j['other_user']) : null,
        pinnedMessage: j['pinned_message'] != null ? Message.fromJson(j['pinned_message']) : null,
        unreadCount: j['unread_count'] ?? 0,
        deleteRequestedBy: j['delete_requested_by'] as int?,
      );
}
