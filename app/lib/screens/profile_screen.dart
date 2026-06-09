import 'dart:io';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/push_service.dart';
import '../services/settings_service.dart';
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
    await PushService().unregister();
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
      body: SingleChildScrollView(
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
                          ? CachedNetworkImageProvider('${ApiService.fileBase}$avatarUrl')
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
            const Divider(),
            const SizedBox(height: 8),
            const _ChangePasswordSection(),
            const SizedBox(height: 8),
            const _ThemeModeSection(),
            const SizedBox(height: 8),
            const Divider(),
            const SizedBox(height: 8),
            const _FontSizeSection(),
            const SizedBox(height: 8),
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

class _ChangePasswordSection extends StatelessWidget {
  const _ChangePasswordSection();

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        const Icon(Icons.lock_outline, size: 18, color: Colors.grey),
        const SizedBox(width: 8),
        const Expanded(child: Text('Jelszó', style: TextStyle(fontSize: 14))),
        TextButton(
          onPressed: () => _showDialog(context),
          child: const Text('Változtatás'),
        ),
      ],
    );
  }

  void _showDialog(BuildContext context) {
    showDialog(context: context, builder: (_) => const _ChangePasswordDialog());
  }
}

class _ChangePasswordDialog extends StatefulWidget {
  const _ChangePasswordDialog();

  @override
  State<_ChangePasswordDialog> createState() => _ChangePasswordDialogState();
}

class _ChangePasswordDialogState extends State<_ChangePasswordDialog> {
  final _currentCtrl = TextEditingController();
  final _newCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _currentCtrl.dispose();
    _newCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final current = _currentCtrl.text;
    final next = _newCtrl.text;
    final confirm = _confirmCtrl.text;
    if (current.isEmpty || next.isEmpty || confirm.isEmpty) {
      setState(() => _error = 'Minden mező kitöltése kötelező.');
      return;
    }
    if (next.length < 6) {
      setState(() => _error = 'Az új jelszó legalább 6 karakter legyen.');
      return;
    }
    if (next != confirm) {
      setState(() => _error = 'A két jelszó nem egyezik.');
      return;
    }
    setState(() { _saving = true; _error = null; });
    try {
      await ApiService().changePassword(current, next);
      if (mounted) {
        Navigator.pop(context);
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Jelszó sikeresen módosítva.')));
      }
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _saving = false; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Jelszó változtatás'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          _PassField(controller: _currentCtrl, label: 'Jelenlegi jelszó'),
          const SizedBox(height: 12),
          _PassField(controller: _newCtrl, label: 'Új jelszó'),
          const SizedBox(height: 12),
          _PassField(controller: _confirmCtrl, label: 'Új jelszó megerősítése'),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: Colors.red, fontSize: 13)),
          ],
        ],
      ),
      actions: [
        TextButton(onPressed: _saving ? null : () => Navigator.pop(context), child: const Text('Mégsem')),
        TextButton(
          onPressed: _saving ? null : _save,
          child: _saving
              ? const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Mentés'),
        ),
      ],
    );
  }
}

class _PassField extends StatefulWidget {
  final TextEditingController controller;
  final String label;
  const _PassField({required this.controller, required this.label});

  @override
  State<_PassField> createState() => _PassFieldState();
}

class _PassFieldState extends State<_PassField> {
  bool _obscure = true;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: widget.controller,
      obscureText: _obscure,
      decoration: InputDecoration(
        labelText: widget.label,
        suffixIcon: IconButton(
          icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined, size: 18),
          onPressed: () => setState(() => _obscure = !_obscure),
        ),
      ),
    );
  }
}

class _ThemeModeSection extends StatefulWidget {
  const _ThemeModeSection();

  @override
  State<_ThemeModeSection> createState() => _ThemeModeSectionState();
}

class _ThemeModeSectionState extends State<_ThemeModeSection> {
  @override
  void initState() {
    super.initState();
    SettingsService().addListener(_rebuild);
  }

  @override
  void dispose() {
    SettingsService().removeListener(_rebuild);
    super.dispose();
  }

  void _rebuild() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final current = SettingsService().themeMode;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Téma', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Theme.of(context).colorScheme.onSurfaceVariant)),
        const SizedBox(height: 10),
        SegmentedButton<ThemeMode>(
          segments: const [
            ButtonSegment(value: ThemeMode.system, icon: Icon(Icons.brightness_auto), label: Text('Rendszer')),
            ButtonSegment(value: ThemeMode.light, icon: Icon(Icons.light_mode), label: Text('Világos')),
            ButtonSegment(value: ThemeMode.dark, icon: Icon(Icons.dark_mode), label: Text('Sötét')),
          ],
          selected: {current},
          onSelectionChanged: (s) => SettingsService().setThemeMode(s.first),
          showSelectedIcon: false,
        ),
      ],
    );
  }
}

class _FontSizeSection extends StatefulWidget {
  const _FontSizeSection();

  @override
  State<_FontSizeSection> createState() => _FontSizeSectionState();
}

class _FontSizeSectionState extends State<_FontSizeSection> {
  @override
  void initState() {
    super.initState();
    SettingsService().addListener(_rebuild);
  }

  @override
  void dispose() {
    SettingsService().removeListener(_rebuild);
    super.dispose();
  }

  void _rebuild() => setState(() {});

  @override
  Widget build(BuildContext context) {
    final scale = SettingsService().fontScale;
    final pct = (scale * 100).round();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Betűméret', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: Theme.of(context).colorScheme.onSurfaceVariant)),
        const SizedBox(height: 10),
        Row(
          children: [
            _ScaleButton(
              icon: Icons.remove,
              onTap: scale <= SettingsService.minScale ? null : () => SettingsService().decrease(),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
                ),
                child: MediaQuery(
                  // Az előnézet a kiválasztott mérettel jelenik meg, nem a rendszer scalerével
                  data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(scale)),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('Előnézet — $pct%',
                              style: const TextStyle(fontSize: 11, color: Colors.grey),
                              textScaler: TextScaler.noScaling),
                          if (scale != 1.0)
                            GestureDetector(
                              onTap: () => SettingsService().reset(),
                              child: const Text('Visszaállítás',
                                  style: TextStyle(fontSize: 11, color: kBlue),
                                  textScaler: TextScaler.noScaling),
                            ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      const Text('Helló! Ez egy előnézet szöveg.'),
                      const SizedBox(height: 2),
                      Text('Ilyen lesz az üzenetek betűmérete.',
                          style: TextStyle(color: Colors.grey.shade600)),
                    ],
                  ),
                ),
              ),
            ),
            const SizedBox(width: 12),
            _ScaleButton(
              icon: Icons.add,
              onTap: scale >= SettingsService.maxScale ? null : () => SettingsService().increase(),
            ),
          ],
        ),
      ],
    );
  }
}

class _ScaleButton extends StatelessWidget {
  final IconData icon;
  final VoidCallback? onTap;
  const _ScaleButton({required this.icon, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final enabled = onTap != null;
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(
          color: enabled ? kBlue : Colors.grey.shade200,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Icon(icon, color: enabled ? Colors.white : Colors.grey, size: 20),
      ),
    );
  }
}
