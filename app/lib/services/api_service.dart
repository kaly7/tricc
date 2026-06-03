import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:mime/mime.dart';
import 'package:http_parser/http_parser.dart';
import '../models/room.dart';
import '../models/message.dart';
import '../models/user.dart';
import 'auth_service.dart';

class ApiService {
  static const String base = 'http://192.168.16.22:9453/tricc/api';

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        if (AuthService().token != null)
          'Authorization': 'Bearer ${AuthService().token}',
      };

  Future<Map<String, dynamic>> _post(String path, Map<String, dynamic> body) async {
    final r = await http.post(
      Uri.parse('$base$path'),
      headers: _headers,
      body: jsonEncode(body),
    );
    return _parse(r);
  }

  Future<Map<String, dynamic>> _get(String path) async {
    final r = await http.get(Uri.parse('$base$path'), headers: _headers);
    return _parse(r);
  }

  Future<Map<String, dynamic>> _put(String path, Map<String, dynamic> body) async {
    final r = await http.put(
      Uri.parse('$base$path'),
      headers: _headers,
      body: jsonEncode(body),
    );
    return _parse(r);
  }

  Future<Map<String, dynamic>> _delete(String path, [Map<String, dynamic>? body]) async {
    final req = http.Request('DELETE', Uri.parse('$base$path'));
    req.headers.addAll(_headers);
    if (body != null) req.body = jsonEncode(body);
    final r = await req.send();
    return _parse(await http.Response.fromStream(r));
  }

  Map<String, dynamic> _parse(http.Response r) {
    final body = jsonDecode(r.body) as Map<String, dynamic>;
    if (r.statusCode >= 400) throw ApiException(body['error'] ?? body['message'] ?? 'Hiba történt', r.statusCode);
    // Response::ok csomagolja: {"ok":true,"data":{...}} → kibontjuk
    if (body['data'] is Map<String, dynamic>) return body['data'] as Map<String, dynamic>;
    return body;
  }

  // Auth
  Future<Map<String, dynamic>> register(String name, String email, String password, String inviteCode) =>
      _post('/auth/register', {'name': name, 'email': email, 'password': password, 'invite_code': inviteCode});

  Future<Map<String, dynamic>> login(String email, String password) =>
      _post('/auth/login', {'email': email, 'password': password});

  Future<Map<String, dynamic>> getMe() => _get('/auth/me');

  Future<void> updateProfile(String name) => _put('/auth/profile', {'name': name});

  // Rooms
  Future<List<Room>> getRooms() async {
    final r = await _get('/rooms');
    return (r['rooms'] as List).map((e) => Room.fromJson(e)).toList();
  }

  Future<Room> getRoom(int id) async {
    final r = await _get('/rooms/$id');
    return Room.fromJson(r['room']);
  }

  Future<int> createDirectRoom(int userId) async {
    final r = await _post('/rooms', {'type': 'direct', 'user_id': userId});
    return r['room_id'];
  }

  Future<int> createGroupRoom(String name, List<int> members) async {
    final r = await _post('/rooms', {'type': 'group', 'name': name, 'members': members});
    return r['room_id'];
  }

  Future<void> addMember(int roomId, int userId) =>
      _post('/rooms/$roomId/members', {'user_id': userId});

  Future<void> removeMember(int roomId, int userId) =>
      _delete('/rooms/$roomId/members/$userId');

  // Messages
  Future<List<Message>> getMessages(int roomId, {int? before, int limit = 50}) async {
    var path = '/rooms/$roomId/messages?limit=$limit';
    if (before != null) path += '&before=$before';
    final r = await _get(path);
    return (r['messages'] as List).map((e) => Message.fromJson(e)).toList();
  }

  Future<Message> sendMessage(int roomId, {
    required String type,
    String? content,
    String? fileUrl,
    String? fileName,
  }) async {
    final body = <String, dynamic>{'type': type};
    if (content != null) body['content'] = content;
    if (fileUrl != null) body['file_url'] = fileUrl;
    if (fileName != null) body['file_name'] = fileName;
    final r = await _post('/rooms/$roomId/messages', body);
    return Message.fromJson(r['message']);
  }

  Future<void> deleteMessage(int roomId, int messageId) =>
      _delete('/rooms/$roomId/messages/$messageId');

  // Upload
  Future<Map<String, dynamic>> uploadFile(File file) async {
    final mime = lookupMimeType(file.path) ?? 'application/octet-stream';
    final parts = mime.split('/');
    final req = http.MultipartRequest('POST', Uri.parse('$base/upload'));
    req.headers['Authorization'] = 'Bearer ${AuthService().token}';
    req.files.add(await http.MultipartFile.fromPath(
      'file', file.path,
      contentType: MediaType(parts[0], parts[1]),
    ));
    final res = await req.send();
    return _parse(await http.Response.fromStream(res));
  }

  Future<String> uploadAvatar(File file) async {
    final req = http.MultipartRequest('POST', Uri.parse('$base/upload/avatar'));
    req.headers['Authorization'] = 'Bearer ${AuthService().token}';
    req.files.add(await http.MultipartFile.fromPath('file', file.path));
    final res = await req.send();
    final data = _parse(await http.Response.fromStream(res));
    return data['avatar_url'];
  }

  // Push
  Future<void> registerPushToken(String token) =>
      _post('/push/register', {'device_token': token});

  Future<void> unregisterPushToken(String token) =>
      _delete('/push/register', {'device_token': token});

  // Users (search / admin)
  Future<List<User>> getUsers() async {
    final r = await _get('/admin/users');
    return (r['users'] as List).map((e) => User.fromJson(e)).toList();
  }
}

class ApiException implements Exception {
  final String message;
  final int statusCode;
  ApiException(this.message, this.statusCode);
  @override
  String toString() => message;
}
