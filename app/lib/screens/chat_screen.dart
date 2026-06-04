import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:image_picker/image_picker.dart';
import 'package:file_picker/file_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:http/http.dart' as http;
import 'package:open_filex/open_filex.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:flutter_markdown/flutter_markdown.dart';
import '../models/user.dart';
import '../models/room.dart';
import '../models/message.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/ws_service.dart';
import '../app_theme.dart';

class ChatScreen extends StatefulWidget {
  final Room room;
  const ChatScreen({super.key, required this.room});

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final _msgCtrl = TextEditingController();
  final _scroll = ScrollController();
  final List<Message> _messages = [];
  late Room _room;
  bool _loading = true;
  bool _sending = false;
  bool _hasMore = true;
  StreamSubscription? _wsSub;

  bool get _isAdmin => _room.members
      .any((m) => m.id == AuthService().userId && m.role == 'admin');

  @override
  void initState() {
    super.initState();
    _room = widget.room;
    _loadRoom();
    _loadMessages();
    WsService().join(widget.room.id);
    _wsSub = WsService().events.listen((msg) => _onWsEvent(msg));
  }

  @override
  void dispose() {
    _wsSub?.cancel();
    WsService().leave(widget.room.id);
    _msgCtrl.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _loadRoom() async {
    try {
      final r = await ApiService().getRoom(widget.room.id);
      if (mounted) setState(() => _room = r);
    } catch (e) {
      debugPrint('[_loadRoom] hiba: $e');
    }
  }

  Future<void> _onWsEvent(Map<String, dynamic> msg) async {
    if (!mounted) return;
    final roomId = msg['room_id'];
    if (roomId != widget.room.id) return;
    if (msg['type'] == 'message') {
      final m = Message.fromJson(msg['message']);
      if (!_messages.any((e) => e.id == m.id)) {
        setState(() => _messages.insert(0, m));
      }
    } else if (msg['type'] == 'delete_request') {
      if (msg['message'] != null) {
        final m = Message.fromJson(msg['message']);
        if (!_messages.any((e) => e.id == m.id)) {
          setState(() => _messages.insert(0, m));
        }
      }
      await _loadRoom(); // await hogy a banner azonnal megjelenjen
    }
  }

  Future<void> _loadMessages({bool older = false}) async {
    final before = older && _messages.isNotEmpty ? _messages.last.id : null;
    try {
      final msgs = await ApiService().getMessages(widget.room.id, before: before);
      setState(() {
        if (!older) _messages.clear();
        _messages.addAll(msgs.reversed);
        _hasMore = msgs.length >= 50;
        _loading = false;
      });
    } catch (_) {
      setState(() => _loading = false);
    }
  }

  Future<void> _sendText() async {
    final text = _msgCtrl.text.trim();
    if (text.isEmpty) return;
    _msgCtrl.clear();
    setState(() => _sending = true);
    try {
      final m = await ApiService().sendMessage(widget.room.id, type: 'text', content: text);
      if (!_messages.any((e) => e.id == m.id)) {
        setState(() => _messages.insert(0, m));
      }
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _pickImage() async {
    final picker = ImagePicker();
    final picked = await picker.pickImage(source: ImageSource.gallery, imageQuality: 85);
    if (picked == null) return;
    await _uploadAndSend(File(picked.path), 'image');
  }

  Future<void> _pickFile() async {
    final result = await FilePicker.platform.pickFiles();
    if (result == null || result.files.single.path == null) return;
    await _uploadAndSend(File(result.files.single.path!), 'file');
  }

  Future<void> _uploadAndSend(File file, String type) async {
    setState(() => _sending = true);
    try {
      final uploaded = await ApiService().uploadFile(file);
      final m = await ApiService().sendMessage(
        widget.room.id,
        type: type,
        fileUrl: uploaded['url'] ?? uploaded['file_url'],
        fileName: uploaded['file_name'],
      );
      if (!_messages.any((e) => e.id == m.id)) {
        setState(() => _messages.insert(0, m));
      }
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  void _showRoomInfo() {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      isScrollControlled: true,
      builder: (_) => _RoomInfoSheet(
        room: _room,
        onDirectMessage: (userId) async {
          Navigator.pop(context);
          try {
            final roomId = await ApiService().createDirectRoom(userId);
            final room = await ApiService().getRoom(roomId);
            if (mounted) Navigator.pushReplacement(context, MaterialPageRoute(builder: (_) => ChatScreen(room: room)));
          } catch (e) {
            if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
          }
        },
        onLeave: () {
          if (mounted) Navigator.pop(context); // chat screen bezárása
        },
        onMembersChanged: () => _loadRoom(),
      ),
    );
  }

  void _confirmDelete({required bool forEveryone}) {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(forEveryone ? 'Törlés mindenkinél' : 'Törlés csak nálam'),
        content: Text(forEveryone
            ? 'A többi résztvevő értesítést kap, és dönthetnek, hogy megtartják-e a beszélgetést.'
            : 'A beszélgetés eltűnik a listádból. A többi résztvevőnél megmarad.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Mégsem')),
          TextButton(
            onPressed: () async {
              Navigator.pop(context);
              try {
                if (forEveryone) {
                  await ApiService().requestDelete(_room.id);
                  await _loadRoom();
                  await _loadMessages();
                } else {
                  await ApiService().hideRoom(_room.id);
                  if (mounted) Navigator.pop(context);
                }
              } catch (e) {
                if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
              }
            },
            child: Text(forEveryone ? 'Törlés kérése' : 'Törlés', style: const TextStyle(color: Colors.red)),
          ),
        ],
      ),
    );
  }

  String get _title => _room.displayName(AuthService().userId ?? 0);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_title),
        actions: [
          if (!_room.isDirect)
            IconButton(icon: const Icon(Icons.info_outline), onPressed: _showRoomInfo),
          PopupMenuButton<String>(
            icon: const Icon(Icons.more_vert),
            onSelected: (v) {
              if (v == 'leave') _confirmDelete(forEveryone: false);
              if (v == 'delete') _confirmDelete(forEveryone: true);
            },
            itemBuilder: (_) => [
              const PopupMenuItem(value: 'leave', child: Row(children: [Icon(Icons.exit_to_app, size: 18), SizedBox(width: 8), Text('Törlés csak nálam')])),
              const PopupMenuItem(value: 'delete', child: Row(children: [Icon(Icons.delete_forever, size: 18, color: Colors.red), SizedBox(width: 8), Text('Törlés mindenkinél', style: TextStyle(color: Colors.red))])),
            ],
          ),
        ],
      ),
      body: Column(
        children: [
          if (_room.deleteRequestedBy != null && _room.deleteRequestedBy == AuthService().userId)
            _PendingDeleteBar()
          else if (_room.deleteRequestedBy != null)
            _DeleteRequestBanner(
              onKeep: () async {
                try {
                  await ApiService().keepRoom(_room.id);
                  await _loadRoom();
                  await _loadMessages();
                } catch (e) {
                  if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
                }
              },
              onDelete: () async {
                try {
                  await ApiService().hideRoom(_room.id);
                  if (mounted) Navigator.pop(context);
                } catch (e) {
                  if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
                }
              },
            ),
          if (_room.pinnedMessage != null)
            _PinnedBar(
              message: _room.pinnedMessage!,
              canUnpin: !_room.isDirect,
              onUnpin: () async {
                try {
                  await ApiService().unpinMessage(_room.id);
                  await _loadRoom();
                } catch (_) {}
              },
            ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : NotificationListener<ScrollNotification>(
                    onNotification: (n) {
                      if (n.metrics.pixels >= n.metrics.maxScrollExtent - 200 && _hasMore) {
                        _loadMessages(older: true);
                      }
                      return false;
                    },
                    child: ListView.builder(
                      controller: _scroll,
                      reverse: true,
                      itemCount: _messages.length,
                      itemBuilder: (_, i) => _MessageBubble(
                        message: _messages[i],
                        isMine: _messages[i].userId != null && _messages[i].userId == AuthService().userId,
                        isGroup: !_room.isDirect,
                        isPinned: _room.pinnedMessage?.id == _messages[i].id,
                        canPin: !_room.isDirect,
                        onPin: () async {
                          try {
                            await ApiService().pinMessage(_room.id, _messages[i].id);
                            await _loadRoom();
                            if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Üzenet kiemelve.')));
                          } catch (e) {
                            if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
                          }
                        },
                      ),
                    ),
                  ),
          ),
          _InputBar(
            controller: _msgCtrl,
            sending: _sending,
            onSend: _sendText,
            onImage: _pickImage,
            onFile: _pickFile,
          ),
        ],
      ),
    );
  }
}

// Kiemelt üzenet sáv
class _PinnedBar extends StatelessWidget {
  final Message message;
  final bool canUnpin;
  final VoidCallback? onUnpin;
  const _PinnedBar({required this.message, this.canUnpin = false, this.onUnpin});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      color: kBlue.withOpacity(0.08),
      child: Row(
        children: [
          const Icon(Icons.push_pin, size: 14, color: kBlue),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              message.content ?? message.fileName ?? 'Kiemelt üzenet',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 12, color: kBlue),
            ),
          ),
          if (canUnpin)
            GestureDetector(
              onTap: onUnpin,
              child: const Icon(Icons.close, size: 16, color: kBlue),
            ),
        ],
      ),
    );
  }
}

// Szoba info (tagok)
class _RoomInfoSheet extends StatefulWidget {
  final Room room;
  final void Function(int userId) onDirectMessage;
  final VoidCallback onLeave;
  final VoidCallback onMembersChanged;
  const _RoomInfoSheet({required this.room, required this.onDirectMessage, required this.onLeave, required this.onMembersChanged});

  @override
  State<_RoomInfoSheet> createState() => _RoomInfoSheetState();
}

class _RoomInfoSheetState extends State<_RoomInfoSheet> {
  List<User> _allUsers = [];
  bool _loadingUsers = false;

  void _showAddMember() async {
    setState(() => _loadingUsers = true);
    final memberIds = widget.room.members.map((m) => m.id).toSet();
    try {
      final users = await ApiService().getUsers();
      final candidates = users.where((u) => !memberIds.contains(u.id)).toList();
      if (!mounted) return;
      setState(() { _allUsers = candidates; _loadingUsers = false; });
      showModalBottomSheet(
        context: context,
        shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
        builder: (_) => Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Tag hozzáadása', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              if (candidates.isEmpty)
                const Padding(padding: EdgeInsets.all(16), child: Text('Nincs más felhasználó.'))
              else
                ...candidates.map((u) => ListTile(
                  leading: CircleAvatar(backgroundColor: kBlue, child: Text(u.name[0].toUpperCase(), style: const TextStyle(color: Colors.white))),
                  title: Text(u.name),
                  onTap: () async {
                    Navigator.pop(context);
                    try {
                      await ApiService().addMember(widget.room.id, u.id);
                      widget.onMembersChanged();
                      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('${u.name} hozzáadva.')));
                    } catch (e) {
                      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
                    }
                  },
                )),
            ],
          ),
        ),
      );
    } catch (_) {
      setState(() => _loadingUsers = false);
    }
  }

  void _confirmLeave() {
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Kilépés'),
        content: const Text('Biztosan ki szeretnél lépni ebből a csoportból?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Mégsem')),
          TextButton(
            onPressed: () async {
              Navigator.pop(context); // dialog
              Navigator.pop(context); // info sheet
              try {
                await ApiService().leaveRoom(widget.room.id);
                widget.onLeave();
              } catch (e) {
                if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
              }
            },
            child: const Text('Kilépés', style: TextStyle(color: Colors.red)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final me = AuthService().userId;
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(child: Text(widget.room.name.isNotEmpty ? widget.room.name : 'Csoport', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold))),
              TextButton.icon(
                onPressed: _loadingUsers ? null : _showAddMember,
                icon: const Icon(Icons.person_add_outlined, size: 18),
                label: const Text('Tag hozzáadása'),
              ),
            ],
          ),
          Text('${widget.room.members.length} tag', style: const TextStyle(color: Colors.grey, fontSize: 13)),
          const Divider(height: 20),
          ...widget.room.members.map((u) => ListTile(
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(
              backgroundColor: kBlue,
              child: Text(u.name.isNotEmpty ? u.name[0].toUpperCase() : '?', style: const TextStyle(color: Colors.white)),
            ),
            title: Text(u.name),
            trailing: u.id != me
                ? IconButton(
                    icon: const Icon(Icons.message_outlined, color: kBlue),
                    tooltip: 'Üzenet küldése',
                    onPressed: () => widget.onDirectMessage(u.id),
                  )
                : const Text('(én)', style: TextStyle(color: Colors.grey, fontSize: 12)),
          )),
          const Divider(height: 20),
          TextButton.icon(
            onPressed: _confirmLeave,
            icon: const Icon(Icons.exit_to_app, color: Colors.red),
            label: const Text('Kilépés a csoportból', style: TextStyle(color: Colors.red)),
          ),
        ],
      ),
    );
  }
}

// A kérő félnél — várakozás jelzés
class _PendingDeleteBar extends StatelessWidget {
  const _PendingDeleteBar();

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      color: Colors.grey.shade100,
      child: const Row(children: [
        Icon(Icons.hourglass_empty, size: 14, color: Colors.grey),
        SizedBox(width: 6),
        Text('Törlési kérés elküldve — várakozás a másik fél döntésére.',
            style: TextStyle(fontSize: 12, color: Colors.grey)),
      ]),
    );
  }
}

// Törlési kérés banner
class _DeleteRequestBanner extends StatelessWidget {
  final VoidCallback onKeep;
  final VoidCallback onDelete;
  const _DeleteRequestBanner({required this.onKeep, required this.onDelete});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      color: Colors.orange.shade50,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(children: [
            Icon(Icons.warning_amber, size: 16, color: Colors.orange),
            SizedBox(width: 6),
            Expanded(child: Text('Valaki törölni szeretné ezt a beszélgetést.', style: TextStyle(fontSize: 13, color: Colors.orange, fontWeight: FontWeight.w600))),
          ]),
          const SizedBox(height: 8),
          Row(children: [
            Expanded(child: OutlinedButton(onPressed: onKeep, child: const Text('Megtartom'))),
            const SizedBox(width: 8),
            Expanded(child: ElevatedButton(
              onPressed: onDelete,
              style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white, minimumSize: const Size(0, 36)),
              child: const Text('Törlöm én is'),
            )),
          ]),
        ],
      ),
    );
  }
}

// Üzenet buborék
class _MessageBubble extends StatelessWidget {
  final Message message;
  final bool isMine;
  final bool isGroup;
  final bool canPin;
  final VoidCallback? onPin;
  final bool isPinned;
  const _MessageBubble({required this.message, required this.isMine, required this.isGroup, this.canPin = false, this.onPin, this.isPinned = false});

  @override
  Widget build(BuildContext context) {
    if (message.type == 'system') {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 24),
        child: Center(child: Text(message.content ?? '', style: const TextStyle(color: Colors.grey, fontStyle: FontStyle.italic, fontSize: 12), textAlign: TextAlign.center)),
      );
    }
    return GestureDetector(
      onLongPress: canPin ? () => showModalBottomSheet(
        context: context,
        builder: (_) => SafeArea(
          child: ListTile(
            leading: const Icon(Icons.push_pin, color: kBlue),
            title: const Text('Üzenet kiemelése'),
            onTap: () { Navigator.pop(context); onPin?.call(); },
          ),
        ),
      ) : null,
      child: Align(
      alignment: isMine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: EdgeInsets.only(top: 4, bottom: 4, left: isMine ? 64 : 12, right: isMine ? 12 : 64),
        child: Column(
          crossAxisAlignment: isMine ? CrossAxisAlignment.end : CrossAxisAlignment.start,
          children: [
            if (!isMine && isGroup)
              Padding(
                padding: const EdgeInsets.only(left: 4, bottom: 2),
                child: Text(message.userName, style: const TextStyle(fontSize: 12, color: Colors.grey, fontWeight: FontWeight.w600)),
              ),
            Container(
              padding: _needsPadding ? const EdgeInsets.symmetric(horizontal: 12, vertical: 8) : EdgeInsets.zero,
              decoration: BoxDecoration(
                color: isMine ? kBlue : const Color(0xFFEEEEEE),
                borderRadius: BorderRadius.only(
                  topLeft: const Radius.circular(16),
                  topRight: const Radius.circular(16),
                  bottomLeft: Radius.circular(isMine ? 16 : 4),
                  bottomRight: Radius.circular(isMine ? 4 : 16),
                ),
              ),
              child: _buildContent(context),
            ),
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (isPinned) const Icon(Icons.push_pin, size: 10, color: kBlue),
                if (isPinned) const SizedBox(width: 2),
                Padding(
                  padding: const EdgeInsets.only(top: 2, left: 4, right: 4),
                  child: Text(_formatTime(message.createdAt), style: const TextStyle(fontSize: 10, color: Colors.grey)),
                ),
              ],
            ),
          ],
        ),
      ),
      ),
    );
  }

  bool get _needsPadding => message.type == 'text' || message.type == 'link' || message.type == 'file' || message.type == 'system';

  Widget _buildContent(BuildContext context) {
    switch (message.type) {
      case 'system':
        return Text(message.content ?? '', style: const TextStyle(color: Colors.grey, fontStyle: FontStyle.italic, fontSize: 13));
      case 'text':
        return MarkdownBody(
          data: message.content ?? '',
          selectable: false,
          styleSheet: MarkdownStyleSheet(
            p: TextStyle(color: isMine ? Colors.white : Colors.black87),
            strong: TextStyle(color: isMine ? Colors.white : Colors.black87, fontWeight: FontWeight.bold),
            em: TextStyle(color: isMine ? Colors.white : Colors.black87, fontStyle: FontStyle.italic),
            del: TextStyle(color: isMine ? Colors.white70 : Colors.black54),
            code: TextStyle(
              color: isMine ? Colors.white : Colors.black87,
              backgroundColor: isMine ? Colors.white24 : Colors.grey.shade200,
              fontFamily: 'monospace',
            ),
          ),
        );
      case 'image':
        return _ImageBubble(fileUrl: message.fileUrl ?? '', isMine: isMine);
      case 'file':
        return _FileBubble(fileName: message.fileName ?? 'Fájl', fileUrl: message.fileUrl ?? '', isMine: isMine);
      case 'link':
        return GestureDetector(
          onTap: () => launchUrl(Uri.parse(message.content ?? ''), mode: LaunchMode.externalApplication),
          child: Text(message.content ?? '', style: TextStyle(color: isMine ? Colors.white : Colors.blue, decoration: TextDecoration.underline)),
        );
      default:
        return const SizedBox.shrink();
    }
  }

  String _formatTime(String iso) {
    try {
      final dt = DateTime.parse(iso).toLocal();
      return '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    } catch (_) {
      return '';
    }
  }
}

// Kép buborék — bélyegkép + letöltés
class _ImageBubble extends StatelessWidget {
  final String fileUrl;
  final bool isMine;
  static const String _serverBase = 'http://192.168.16.22:9453';
  const _ImageBubble({required this.fileUrl, required this.isMine});

  String get fullUrl => '$_serverBase$fileUrl';

  @override
  Widget build(BuildContext context) {
    return Stack(
      alignment: Alignment.bottomRight,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: CachedNetworkImage(
            imageUrl: fullUrl,
            width: 220,
            fit: BoxFit.cover,
            placeholder: (context, url) => const SizedBox(height: 120, child: Center(child: CircularProgressIndicator())),
            errorWidget: (context, url, err) => const SizedBox(height: 60, child: Center(child: Icon(Icons.broken_image))),
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(4),
          child: CircleAvatar(
            radius: 14,
            backgroundColor: Colors.black45,
            child: IconButton(
              icon: const Icon(Icons.download, color: Colors.white, size: 14),
              padding: EdgeInsets.zero,
              onPressed: () => _download(context),
            ),
          ),
        ),
      ],
    );
  }

  Future<void> _download(BuildContext context) async {
    try {
      final res = await http.get(Uri.parse(fullUrl));
      final dir = await getTemporaryDirectory();
      final fileName = fileUrl.split('/').last;
      final path = '${dir.path}/$fileName';
      await File(path).writeAsBytes(res.bodyBytes);
      await OpenFilex.open(path);
    } catch (_) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Letöltés sikertelen.')));
    }
  }
}

// Fájl buborék — csak név + letöltés ikon
class _FileBubble extends StatelessWidget {
  final String fileName;
  final String fileUrl;
  final bool isMine;
  static const String _serverBase = 'http://192.168.16.22:9453';
  const _FileBubble({required this.fileName, required this.fileUrl, required this.isMine});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => _open(context),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.insert_drive_file_outlined, color: isMine ? Colors.white : kBlue, size: 20),
          const SizedBox(width: 8),
          Flexible(child: Text(fileName, style: TextStyle(color: isMine ? Colors.white : Colors.black87))),
          const SizedBox(width: 8),
          Icon(Icons.download_outlined, color: isMine ? Colors.white70 : Colors.grey, size: 18),
        ],
      ),
    );
  }

  Future<void> _open(BuildContext context) async {
    try {
      final url = '$_serverBase$fileUrl';
      final res = await http.get(Uri.parse(url));
      final dir = await getTemporaryDirectory();
      final path = '${dir.path}/$fileName';
      await File(path).writeAsBytes(res.bodyBytes);
      await OpenFilex.open(path);
    } catch (_) {
      if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Megnyitás sikertelen.')));
    }
  }
}

// Input sáv
class _InputBar extends StatelessWidget {
  final TextEditingController controller;
  final bool sending;
  final VoidCallback onSend;
  final VoidCallback onImage;
  final VoidCallback onFile;
  const _InputBar({required this.controller, required this.sending, required this.onSend, required this.onImage, required this.onFile});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(8, 8, 8, MediaQuery.of(context).viewInsets.bottom + 8),
      decoration: BoxDecoration(
        color: Theme.of(context).scaffoldBackgroundColor,
        boxShadow: [BoxShadow(color: Colors.black12, blurRadius: 4, offset: const Offset(0, -2))],
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            IconButton(icon: const Icon(Icons.image_outlined), onPressed: sending ? null : onImage),
            IconButton(icon: const Icon(Icons.attach_file), onPressed: sending ? null : onFile),
            Expanded(
              child: TextField(
                controller: controller,
                decoration: InputDecoration(
                  hintText: 'Üzenet...',
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(24), borderSide: BorderSide.none),
                  filled: true,
                  fillColor: Colors.grey.shade100,
                ),
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => onSend(),
                maxLines: null,
              ),
            ),
            const SizedBox(width: 4),
            CircleAvatar(
              backgroundColor: kBlue,
              child: sending
                  ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                  : IconButton(icon: const Icon(Icons.send, color: Colors.white, size: 18), onPressed: onSend),
            ),
          ],
        ),
      ),
    );
  }
}
