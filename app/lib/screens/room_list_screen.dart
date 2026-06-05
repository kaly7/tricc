import 'dart:async';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../models/room.dart';
import '../models/user.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/push_service.dart';
import '../services/ws_service.dart';
import '../app_theme.dart';
import '../widgets/ws_status_bar.dart';
import 'chat_screen.dart';
import 'profile_screen.dart';

class RoomListScreen extends StatefulWidget {
  const RoomListScreen({super.key});

  @override
  State<RoomListScreen> createState() => _RoomListScreenState();
}

class _RoomListScreenState extends State<RoomListScreen> {
  List<Room> _rooms = [];
  bool _loading = true;
  StreamSubscription? _wsSub;

  @override
  void initState() {
    super.initState();
    _load();
    _wsSub = WsService().events.listen((msg) {
      if (msg['type'] == 'message' || msg['type'] == 'delete_request') _load();
    });
    PushService().onNotificationTap = _onPushTap;
  }

  @override
  void dispose() {
    PushService().onNotificationTap = null;
    _wsSub?.cancel();
    super.dispose();
  }

  Future<void> _onPushTap(Map<String, dynamic> data) async {
    final roomId = int.tryParse(data['room_id']?.toString() ?? '');
    if (roomId == null || !mounted) return;
    try {
      final room = await ApiService().getRoom(roomId);
      if (mounted) _openRoom(room);
    } catch (_) {}
  }

  Future<void> _load() async {
    try {
      final rooms = await ApiService().getRooms();
      if (mounted) setState(() { _rooms = rooms; _loading = false; });
      final totalUnread = rooms.fold<int>(0, (s, r) => s + r.unreadCount);
      PushService().setBadge(totalUnread);
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _openRoom(Room room) async {
    ApiService().markRead(room.id).catchError((_) {});
    await Navigator.push(context, MaterialPageRoute(builder: (_) => ChatScreen(room: room)));
    _load();
  }

  Future<void> _toggleMute(Room room) async {
    try {
      if (room.isMuted) {
        await ApiService().unmuteRoom(room.id);
      } else {
        await ApiService().muteRoom(room.id);
      }
      _load();
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
    }
  }

  void _showNewRoomDialog() {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => _NewRoomSheet(onCreated: (roomId) async {
        final room = await ApiService().getRoom(roomId);
        if (mounted) _openRoom(room);
        _load();
      }),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const AppBarLogo(),
        actions: [
          IconButton(
            icon: const Icon(Icons.person),
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ProfileScreen())),
          ),
        ],
      ),
      body: Column(
        children: [
          const WsStatusBar(),
          Expanded(child: Stack(
        children: [
          Center(
            child: Opacity(
              opacity: 0.06,
              child: Image.asset('assets/logo.png',
                  width: double.infinity, fit: BoxFit.fitWidth),
            ),
          ),
          if (_loading)
            const Center(child: CircularProgressIndicator())
          else if (_rooms.isEmpty)
            const Center(child: Text('Még nincs beszélgetésed.\nHozz létre egyet!', textAlign: TextAlign.center))
          else RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.builder(
                    itemCount: _rooms.length,
                    itemBuilder: (_, i) => _RoomTile(
              room: _rooms[i],
              onTap: () => _openRoom(_rooms[i]),
              onMuteToggle: () => _toggleMute(_rooms[i]),
            ),
                  ),
                ),
        ],
          )),
        ],
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: _showNewRoomDialog,
        child: const Icon(Icons.edit),
      ),
    );
  }
}

class _RoomTile extends StatelessWidget {
  final Room room;
  final VoidCallback onTap;
  final VoidCallback onMuteToggle;
  const _RoomTile({required this.room, required this.onTap, required this.onMuteToggle});

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: _RoomAvatar(room: room),
      title: Row(
        children: [
          Expanded(
            child: Text(
              room.displayName(AuthService().userId ?? 0),
              style: TextStyle(fontWeight: room.unreadCount > 0 ? FontWeight.bold : FontWeight.w600),
            ),
          ),
          if (room.isMuted)
            const Padding(
              padding: EdgeInsets.only(left: 4),
              child: Icon(Icons.notifications_off, size: 14, color: Colors.grey),
            ),
        ],
      ),
      subtitle: room.lastMessage != null
          ? Text(
              room.lastMessage!,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontWeight: room.unreadCount > 0 ? FontWeight.bold : FontWeight.normal),
            )
          : null,
      trailing: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (room.lastMessageAt != null)
            Text(_formatTime(room.lastMessageAt!), style: const TextStyle(fontSize: 12, color: Colors.grey)),
          if (room.unreadCount > 0) ...[
            const SizedBox(width: 6),
            Container(
              constraints: const BoxConstraints(minWidth: 20),
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 2),
              decoration: BoxDecoration(color: Colors.red, borderRadius: BorderRadius.circular(10)),
              child: Text(
                room.unreadCount > 99 ? '99+' : '${room.unreadCount}',
                style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
                textAlign: TextAlign.center,
              ),
            ),
          ],
          if (room.deleteRequestedBy != null && room.deleteRequestedBy != AuthService().userId) ...[
            const SizedBox(width: 6),
            const Icon(Icons.warning_amber, size: 16, color: Colors.orange),
          ],
        ],
      ),
      onTap: onTap,
      onLongPress: () => _showContextMenu(context),
    );
  }

  void _showContextMenu(BuildContext context) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: Icon(room.isMuted ? Icons.notifications_active : Icons.notifications_off),
              title: Text(room.isMuted ? 'Értesítések bekapcsolása' : 'Értesítések némítása'),
              subtitle: Text(room.isMuted
                  ? 'Push értesítések újra jönnek'
                  : 'Üzenetek érkeznek, de push nem jön'),
              onTap: () { Navigator.pop(context); onMuteToggle(); },
            ),
          ],
        ),
      ),
    );
  }

  String _formatTime(String iso) {
    try {
      final dt = DateTime.parse(iso).toLocal();
      final now = DateTime.now();
      if (dt.day == now.day && dt.month == now.month && dt.year == now.year) {
        return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
      }
      return '${dt.month}.${dt.day}.';
    } catch (_) {
      return '';
    }
  }
}

class _RoomAvatar extends StatelessWidget {
  final Room room;
  const _RoomAvatar({required this.room});

  static const String _serverBase = 'https://192.168.16.22:9456';

  Color _presenceColor(int? userId) {
    if (userId == null) return Colors.transparent;
    return WsService().onlineUsers.contains(userId) ? const Color(0xFF4CAF50) : Colors.grey.shade400;
  }

  @override
  Widget build(BuildContext context) {
    final myId = AuthService().userId ?? 0;
    final name = room.displayName(myId);
    final avatarUrl = room.isDirect ? room.otherAvatarUrl(myId) : null;
    final otherId = room.isDirect ? room.otherUserId(myId) : null;
    final borderColor = room.isDirect ? _presenceColor(otherId) : Colors.transparent;

    return Stack(
      children: [
        Container(
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(color: borderColor, width: 2.5),
          ),
          child: CircleAvatar(
            backgroundColor: room.isDirect ? kBlue : kLime,
            backgroundImage: avatarUrl != null
                ? CachedNetworkImageProvider('$_serverBase$avatarUrl')
                : null,
            child: avatarUrl == null
                ? Text(name.isNotEmpty ? name[0].toUpperCase() : '?',
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold))
                : null,
          ),
        ),
        Positioned(
          bottom: 0, right: 0,
          child: Container(
            padding: const EdgeInsets.all(2),
            decoration: const BoxDecoration(color: Colors.white, shape: BoxShape.circle),
            child: Icon(
              room.isDirect ? Icons.person : Icons.group,
              size: 10,
              color: room.isDirect ? kBlue : kLime,
            ),
          ),
        ),
      ],
    );
  }
}

// Új szoba létrehozása bottomsheet
class _NewRoomSheet extends StatefulWidget {
  final void Function(int roomId) onCreated;
  const _NewRoomSheet({required this.onCreated});

  @override
  State<_NewRoomSheet> createState() => _NewRoomSheetState();
}

class _NewRoomSheetState extends State<_NewRoomSheet> {
  final _nameCtrl = TextEditingController();
  List<User> _users = [];
  final Set<int> _selected = {};
  bool _loading = true;
  bool _creating = false;
  bool _isGroup = false;

  @override
  void initState() {
    super.initState();
    _loadUsers();
  }

  Future<void> _loadUsers() async {
    try {
      final users = await ApiService().getUsers();
      final me = AuthService().userId;
      setState(() {
        _users = users.where((u) => u.id != me).toList();
        _loading = false;
      });
    } catch (_) {
      setState(() => _loading = false);
    }
  }

  Future<void> _create() async {
    if (_selected.isEmpty) return;
    setState(() => _creating = true);
    try {
      int roomId;
      if (!_isGroup && _selected.length == 1) {
        roomId = await ApiService().createDirectRoom(_selected.first);
      } else {
        final name = _nameCtrl.text.trim().isEmpty ? 'Csoport' : _nameCtrl.text.trim();
        roomId = await ApiService().createGroupRoom(name, _selected.toList());
      }
      if (mounted) {
        Navigator.pop(context);
        widget.onCreated(roomId);
      }
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      if (mounted) setState(() => _creating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.of(context).viewInsets.bottom + 16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text('Új beszélgetés', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(value: false, label: Text('Direkt üzenet'), icon: Icon(Icons.person)),
              ButtonSegment(value: true, label: Text('Csoport'), icon: Icon(Icons.group)),
            ],
            selected: {_isGroup},
            onSelectionChanged: (v) => setState(() {
              _isGroup = v.first;
              _selected.clear();
            }),
          ),
          const SizedBox(height: 12),
          if (_isGroup) ...[
            TextField(controller: _nameCtrl, decoration: const InputDecoration(labelText: 'Csoport neve')),
            const SizedBox(height: 8),
          ],
          Text(
            _isGroup ? 'Tagok kiválasztása:' : 'Kivel szeretnél beszélgetni?',
            style: const TextStyle(fontWeight: FontWeight.w500),
          ),
          const SizedBox(height: 8),
          if (_loading)
            const Center(child: CircularProgressIndicator())
          else
            SizedBox(
              height: 200,
              child: ListView(
                children: _users.map((u) => CheckboxListTile(
                  title: Text(u.name),
                  subtitle: Text(u.email, style: const TextStyle(fontSize: 12)),
                  value: _selected.contains(u.id),
                  onChanged: (v) {
                    setState(() {
                      if (v == true) {
                        if (!_isGroup) _selected.clear();
                        _selected.add(u.id);
                      } else {
                        _selected.remove(u.id);
                      }
                    });
                  },
                )).toList(),
              ),
            ),
          const SizedBox(height: 12),
          ElevatedButton(
            onPressed: (_selected.isEmpty || _creating) ? null : _create,
            child: _creating
                ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                : const Text('Létrehozás'),
          ),
        ],
      ),
    );
  }
}
