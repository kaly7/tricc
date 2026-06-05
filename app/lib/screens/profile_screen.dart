import 'dart:io';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/push_service.dart';
import '../services/ws_service.dart';
import '../app_theme.dart';
import 'login_screen.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  late TextEditingController _nameCtrl;
  bool _saving = false;
  String? _pushToken;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _loadPushToken();
  }

  Future<void> _loadPushToken() async {
    final prefs = await SharedPreferences.getInstance();
    if (mounted) setState(() => _pushToken = prefs.getString('apns_device_token'));
  }

  Future<void> _retryPushToken() async {
    setState(() => _saving = true);
    try {
      await PushService().init();
      await Future.delayed(const Duration(seconds: 2));
      await _loadPushToken();
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  void initState() {
    super.initState();
    _nameCtrl = TextEditingController(text: AuthService().userName ?? '');
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    super.dispose();
  }

  Future<void> _saveProfile() async {
    setState(() => _saving = true);
    try {
      await ApiService().updateProfile(_nameCtrl.text.trim());
      await AuthService().updateProfile(name: _nameCtrl.text.trim());
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Profil mentve.')));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _changeAvatar() async {
    final picker = ImagePicker();
    final picked = await picker.pickImage(source: ImageSource.gallery, imageQuality: 80);
    if (picked == null) return;
    setState(() => _saving = true);
    try {
      final url = await ApiService().uploadAvatar(File(picked.path));
      await AuthService().updateProfile(avatarUrl: url);
      setState(() {});
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _logout() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Kijelentkezés'),
        content: const Text('Biztosan ki szeretnél jelentkezni?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Mégsem')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Igen', style: TextStyle(color: Colors.red))),
        ],
      ),
    );
    if (ok != true) return;
    WsService().disconnect();
    await AuthService().logout();
    if (mounted) {
      Navigator.pushAndRemoveUntil(context, MaterialPageRoute(builder: (_) => const LoginScreen()), (_) => false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final avatarUrl = AuthService().avatarUrl;
    return Scaffold(
      appBar: AppBar(title: const Text('Profil')),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: GestureDetector(
                onTap: _changeAvatar,
                child: Stack(
                  children: [
                    CircleAvatar(
                      radius: 48,
                      backgroundColor: kBlue,
                      backgroundImage: avatarUrl != null
                          ? CachedNetworkImageProvider('https://192.168.16.22:9456$avatarUrl')
                          : null,
                      child: avatarUrl == null
                          ? Text(
                              (AuthService().userName ?? '?')[0].toUpperCase(),
                              style: const TextStyle(fontSize: 36, color: Colors.white, fontWeight: FontWeight.bold),
                            )
                          : null,
                    ),
                    Positioned(
                      bottom: 0, right: 0,
                      child: CircleAvatar(
                        radius: 14,
                        backgroundColor: Colors.white,
                        child: const Icon(Icons.camera_alt, size: 16, color: Color(0xFF1A73E8)),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 32),
            TextField(
              controller: _nameCtrl,
              decoration: const InputDecoration(labelText: 'Teljes név'),
              textInputAction: TextInputAction.done,
            ),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: _saving ? null : _saveProfile,
              child: _saving
                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                  : const Text('Mentés'),
            ),
            const SizedBox(height: 24),
            const Divider(),
            const SizedBox(height: 8),
            _PushStatusTile(token: _pushToken, onRetry: _retryPushToken, saving: _saving),
            const SizedBox(height: 8),
            const Divider(),
            const SizedBox(height: 8),
            TextButton.icon(
              onPressed: _logout,
              icon: const Icon(Icons.logout, color: Colors.red),
              label: const Text('Kijelentkezés', style: TextStyle(color: Colors.red)),
            ),
          ],
        ),
      ),
    );
  }
}

class _PushStatusTile extends StatelessWidget {
  final String? token;
  final VoidCallback onRetry;
  final bool saving;
  const _PushStatusTile({required this.token, required this.onRetry, required this.saving});

  @override
  Widget build(BuildContext context) {
    final hasToken = token != null && token!.isNotEmpty;
    return Row(
      children: [
        Icon(
          hasToken ? Icons.notifications_active : Icons.notifications_off,
          color: hasToken ? Colors.green : Colors.red,
          size: 20,
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            hasToken ? 'Push: ${token!.substring(0, 12)}…' : 'Push: nincs token',
            style: TextStyle(fontSize: 13, color: hasToken ? Colors.green : Colors.red),
          ),
        ),
        TextButton(
          onPressed: saving ? null : onRetry,
          child: const Text('Újra', style: TextStyle(fontSize: 12)),
        ),
      ],
    );
  }
}
