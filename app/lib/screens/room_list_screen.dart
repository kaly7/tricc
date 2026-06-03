import 'package:flutter/material.dart';
import '../models/room.dart';
import '../models/user.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/ws_service.dart';
import '../app_theme.dart';
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

  @override
  void initState() {
    super.initState();
    _load();
    // Frissítés ha WS-en új üzenet érkezik
    WsService().events.listen((msg) {
      if (msg['type'] == 'message') _load();
    });
  }

  Future<void> _load() async {
    try {
      final rooms = await ApiService().getRooms();
      if (mounted) setState(() { _rooms = rooms; _loading = false; });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _openRoom(Room room) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => ChatScreen(room: room)));
    _load();
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
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _rooms.isEmpty
              ? const Center(child: Text('Még nincs beszélgetésed.\nHozz létre egyet!', textAlign: TextAlign.center))
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.builder(
                    itemCount: _rooms.length,
                    itemBuilder: (_, i) => _RoomTile(room: _rooms[i], onTap: () => _openRoom(_rooms[i])),
                  ),
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
  const _RoomTile({required this.room, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return ListTile(
      leading: _RoomAvatar(room: room),
      title: Text(room.displayName(AuthService().userId ?? 0), style: const TextStyle(fontWeight: FontWeight.w600)),
      subtitle: room.lastMessage != null
          ? Text(room.lastMessage!, maxLines: 1, overflow: TextOverflow.ellipsis)
          : null,
      trailing: room.lastMessageAt != null
          ? Text(_formatTime(room.lastMessageAt!), style: const TextStyle(fontSize: 12, color: Colors.grey))
          : null,
      onTap: onTap,
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

  @override
  Widget build(BuildContext context) {
    return CircleAvatar(
      backgroundColor: kBlue,
      child: Text(
        room.displayName(AuthService().userId ?? 0).isNotEmpty ? room.displayName(AuthService().userId ?? 0)[0].toUpperCase() : '?',
        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
      ),
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
