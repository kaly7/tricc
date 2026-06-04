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

  MessageDelivery copyWith({DateTime? deliveredAt, DateTime? readAt, bool clearDeliveredAt = false, bool clearReadAt = false}) =>
      MessageDelivery(
        userId: userId,
        deliveredAt: clearDeliveredAt ? null : (deliveredAt ?? this.deliveredAt),
        readAt: clearReadAt ? null : (readAt ?? this.readAt),
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
  final String createdAt;
  final List<MessageDelivery> deliveries;

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
    required this.createdAt,
    this.deliveries = const [],
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
        createdAt: j['created_at'] ?? '',
        deliveries: (j['deliveries'] as List?)
                ?.map((e) => MessageDelivery.fromJson(e as Map<String, dynamic>))
                .toList() ??
            [],
      );

  Message copyWith({List<MessageDelivery>? deliveries}) => Message(
        id: id,
        roomId: roomId,
        userId: userId,
        userName: userName,
        avatarUrl: avatarUrl,
        type: type,
        content: content,
        fileUrl: fileUrl,
        fileName: fileName,
        createdAt: createdAt,
        deliveries: deliveries ?? this.deliveries,
      );
}
