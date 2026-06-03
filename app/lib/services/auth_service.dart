import 'package:shared_preferences/shared_preferences.dart';

class AuthService {
  static final AuthService _i = AuthService._();
  factory AuthService() => _i;
  AuthService._();

  String? _token;
  int? _userId;
  String? _userName;
  String? _avatarUrl;
  bool _isAdmin = false;

  String? get token => _token;
  int? get userId => _userId;
  String? get userName => _userName;
  String? get avatarUrl => _avatarUrl;
  bool get isAdmin => _isAdmin;
  bool get isLoggedIn => _token != null;

  Future<void> init() async {
    final p = await SharedPreferences.getInstance();
    _token = p.getString('token');
    _userId = p.getInt('user_id');
    _userName = p.getString('user_name');
    _avatarUrl = p.getString('avatar_url');
    _isAdmin = p.getBool('is_admin') ?? false;
  }

  Future<void> setAuth({
    required String token,
    required int userId,
    required String name,
    String? avatarUrl,
    bool isAdmin = false,
  }) async {
    _token = token;
    _userId = userId;
    _userName = name;
    _avatarUrl = avatarUrl;
    _isAdmin = isAdmin;
    final p = await SharedPreferences.getInstance();
    await p.setString('token', token);
    await p.setInt('user_id', userId);
    await p.setString('user_name', name);
    if (avatarUrl != null) await p.setString('avatar_url', avatarUrl);
    await p.setBool('is_admin', isAdmin);
  }

  Future<void> updateProfile({String? name, String? avatarUrl}) async {
    final p = await SharedPreferences.getInstance();
    if (name != null) {
      _userName = name;
      await p.setString('user_name', name);
    }
    if (avatarUrl != null) {
      _avatarUrl = avatarUrl;
      await p.setString('avatar_url', avatarUrl);
    }
  }

  Future<void> logout() async {
    _token = null;
    _userId = null;
    _userName = null;
    _avatarUrl = null;
    _isAdmin = false;
    final p = await SharedPreferences.getInstance();
    await p.clear();
  }
}
