class Message {
  final int id;
  final int? roomId;
  final int? userId;
  final String userName;
  final String? avatarUrl;
  final String type; // 'text' | 'image' | 'file' | 'link'
  final String? content;
  final String? fileUrl;
  final String? fileName;
  final String createdAt;

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
      );
}
