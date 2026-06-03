class User {
  final int id;
  final String name;
  final String email;
  final String? avatarUrl;
  final bool isAdmin;
  final String role; // 'admin' | 'member'

  User({
    required this.id,
    required this.name,
    required this.email,
    this.avatarUrl,
    this.isAdmin = false,
    this.role = 'member',
  });

  factory User.fromJson(Map<String, dynamic> j) => User(
        id: j['id'],
        name: j['name'] ?? '',
        email: j['email'] ?? '',
        avatarUrl: (j['avatar_url'] as String?)?.isNotEmpty == true ? j['avatar_url'] : null,
        isAdmin: j['is_admin'] == true || j['is_admin'] == 1,
        role: j['role'] ?? 'member',
      );
}
