class MessageDelivery {
  final int userId;
  final DateTime? deliveredAt;
  final DateTime? readAt;

  const MessageDelivery({required this.userId, this.deliveredAt, this.readAt});

  factory MessageDelivery.fromJson(Map<String, dynamic> j) => MessageDelivery(
        userId: j['user_id'] as int,
        deliveredAt: j['delivered_at'] != null ? DateTime.tryParse(j['delivered_at'] as String) : null,
        readAt: j['read_at'] != null ? DateTime.tryParse(j['read_at'] as String) : null,
      );

  MessageDelivery copyWith({DateTime? deliveredAt, DateTime? readAt}) =>
      MessageDelivery(
        userId: userId,
        deliveredAt: deliveredAt ?? this.deliveredAt,
        readAt: readAt ?? this.readAt,
      );
}

class ReplyTo {
  final int id;
  final String content;
  final String userName;

  const ReplyTo({required this.id, required this.content, required this.userName});

  factory ReplyTo.fromJson(Map<String, dynamic> j) => ReplyTo(
        id: j['id'] as int,
        content: j['content'] as String? ?? '',
        userName: j['user_name'] as String? ?? '',
      );
}

class MessageReaction {
  final String emoji;
  final int count;
  final bool mine;
  final List<int> userIds;

  const MessageReaction({required this.emoji, required this.count, required this.mine, this.userIds = const []});

  factory MessageReaction.fromJson(Map<String, dynamic> j) => MessageReaction(
        emoji: j['emoji'] as String,
        count: j['count'] as int? ?? 0,
        mine: j['mine'] == true || j['mine'] == 1,
        userIds: (j['user_ids'] as List?)?.map((e) => e as int).toList() ?? [],
      );
}

class Message {
  final int id;
  final int? roomId;
  final int? userId;
  final String userName;
  final String? avatarUrl;
  final String type;
  final String? content;
  final String? fileUrl;
  final String? fileName;
  final int? fileSize;
  final String createdAt;
  final bool isEdited;
  final List<MessageDelivery> deliveries;
  final ReplyTo? replyTo;
  final List<MessageReaction> reactions;

  Message({
    required this.id,
    this.roomId,
    this.userId,
    required this.userName,
    this.avatarUrl,
    required this.type,
    this.content,
    this.fileUrl,
    this.fileName,
    this.fileSize,
    required this.createdAt,
    this.isEdited = false,
    this.deliveries = const [],
    this.replyTo,
    this.reactions = const [],
  });

  factory Message.fromJson(Map<String, dynamic> j) => Message(
        id: j['id'],
        roomId: j['room_id'] as int?,
        userId: j['user_id'] as int?,
        userName: j['user_name'] ?? '',
        avatarUrl: j['avatar_url'],
        type: j['type'] ?? 'text',
        content: j['content'],
        fileUrl: j['file_url'],
        fileName: j['file_name'],
        fileSize: j['file_size'] as int?,
        createdAt: j['created_at'] ?? '',
        isEdited: j['is_edited'] == true || j['is_edited'] == 1,
        deliveries: (j['deliveries'] as List?)
                ?.map((e) => MessageDelivery.fromJson(e as Map<String, dynamic>))
                .toList() ??
            [],
        replyTo: j['reply_to'] != null ? ReplyTo.fromJson(j['reply_to'] as Map<String, dynamic>) : null,
        reactions: (j['reactions'] as List?)
                ?.map((e) => MessageReaction.fromJson(e as Map<String, dynamic>))
                .toList() ??
            [],
      );

  Message copyWith({List<MessageDelivery>? deliveries, List<MessageReaction>? reactions}) =>
      Message(
        id: id,
        roomId: roomId,
        userId: userId,
        userName: userName,
        avatarUrl: avatarUrl,
        type: type,
        content: content,
        fileUrl: fileUrl,
        fileName: fileName,
        fileSize: fileSize,
        createdAt: createdAt,
        isEdited: isEdited,
        deliveries: deliveries ?? this.deliveries,
        replyTo: replyTo,
        reactions: reactions ?? this.reactions,
      );
}
