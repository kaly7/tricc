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
import 'package:flutter/services.dart';
import 'package:flutter_markdown/flutter_markdown.dart';
import '../models/user.dart';
import '../models/room.dart';
import '../models/message.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/ws_service.dart';
import '../app_theme.dart';
import '../widgets/ws_status_bar.dart' show WsDot, PresenceDot, showAvatarDialog;

class ChatScreen extends StatefulWidget {
  final Room room;
  const ChatScreen({super.key, required this.room});

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> with WidgetsBindingObserver {
  final _msgCtrl = TextEditingController();
  final _scroll = ScrollController();
  final List<Message> _messages = [];
  late Room _room;
  bool _loading = true;
  bool _sending = false;
  bool _hasMore = true;
  StreamSubscription? _wsSub;
  bool _someoneRequestedDelete = false;
  Message? _replyTo;
  Message? _editingMessage;
  final Set<int> _pendingMentionIds = {};
  bool _mentionAll = false;
  bool _showMentionPicker = false;
  String _mentionQuery = '';
  final Set<int> _typingUsers = {};
  final Map<int, Timer?> _typingTimers = {};
  Timer? _typingThrottle;
  bool _showScrollDown = false;
  int _unreadWhileScrolled = 0;

  bool get _isAdmin => _room.members
      .any((m) => m.id == AuthService().userId && m.role == 'admin');

  @override
  void initState() {
    super.initState();
    _room = widget.room;
    // Ha a szoba már tartalmaz törlési kérést (pl. főoldalról lépünk be)
    if (_room.deleteRequestedBy != null && _room.deleteRequestedBy != AuthService().userId) {
      _someoneRequestedDelete = true;
    }
    _loadRoom();
    _loadMessages();
    WsService().join(widget.room.id);
    _wsSub = WsService().events.listen((msg) => _onWsEvent(msg));
    _msgCtrl.addListener(_onTextChanged);
    _scroll.addListener(_onScroll);
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    _wsSub?.cancel();
    WsService().leave(widget.room.id);
    ApiService().markRead(widget.room.id).catchError((_) {});
    WidgetsBinding.instance.removeObserver(this);
    _msgCtrl.removeListener(_onTextChanged);
    _scroll.removeListener(_onScroll);
    _typingThrottle?.cancel();
    for (final t in _typingTimers.values) t?.cancel();
    WsService().sendTyping(widget.room.id, false);
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

  void _onScroll() {
    if (!_scroll.hasClients) return;
    final show = _scroll.offset > 300;
    if (_showScrollDown == show) return;
    setState(() {
      _showScrollDown = show;
      if (!show) _unreadWhileScrolled = 0;
    });
  }

  void _sendTypingThrottled() {
    if (_typingThrottle?.isActive == true) return;
    WsService().sendTyping(widget.room.id, true);
    _typingThrottle = Timer(const Duration(seconds: 2), () {});
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && mounted) {
      _loadMessages();
    }
  }

  String? _getMentionQuery() {
    if (_editingMessage != null) return null;
    final text = _msgCtrl.text;
    final cursor = _msgCtrl.selection.baseOffset;
    if (cursor < 0 || cursor > text.length) return null;
    final before = text.substring(0, cursor);
    final atIdx = before.lastIndexOf('@');
    if (atIdx < 0) return null;
    final query = before.substring(atIdx + 1);
    if (query.contains(' ') || query.contains('\n')) return null;
    return query;
  }

  void _onTextChanged() {
    final query = _getMentionQuery();
    final show = query != null;
    if (_showMentionPicker != show || _mentionQuery != (query ?? '')) {
      setState(() {
        _showMentionPicker = show;
        _mentionQuery = query ?? '';
      });
    }
    if (_editingMessage == null) {
      if (_msgCtrl.text.isNotEmpty) {
        _sendTypingThrottled();
      } else {
        _typingThrottle?.cancel();
        WsService().sendTyping(widget.room.id, false);
      }
    }
  }

  void _selectMention(User? user) {
    final text = _msgCtrl.text;
    final cursor = _msgCtrl.selection.baseOffset;
    if (cursor < 0) return;
    final before = text.substring(0, cursor);
    final atIdx = before.lastIndexOf('@');
    if (atIdx < 0) return;
    final after = text.substring(cursor);
    final name = user?.name ?? 'all';
    final newText = '${text.substring(0, atIdx)}@$name $after';
    _msgCtrl.text = newText;
    _msgCtrl.selection = TextSelection.fromPosition(
      TextPosition(offset: atIdx + name.length + 2),
    );
    if (user == null) {
      setState(() { _mentionAll = true; _showMentionPicker = false; });
    } else {
      setState(() { _pendingMentionIds.add(user.id); _showMentionPicker = false; });
    }
  }

  List<User> _getMentionSuggestions() {
    final query = _mentionQuery.toLowerCase();
    return _room.members
        .where((m) => m.id != AuthService().userId)
        .where((m) => query.isEmpty || m.name.toLowerCase().contains(query))
        .toList();
  }

  Future<void> _onWsEvent(Map<String, dynamic> msg) async {
    if (!mounted) return;
    final type = msg['type'] as String?;
    if (type == 'presence' || type == 'presence_list') {
      if (_room.isDirect) setState(() {});
      return;
    }
    final roomId = msg['room_id'];
    if (roomId != widget.room.id) return;
    if (msg['type'] == 'message') {
      final m = Message.fromJson(msg['message']);
      if (!_messages.any((e) => e.id == m.id)) {
        setState(() {
          _messages.insert(0, m);
          if (_showScrollDown && m.userId != AuthService().userId) _unreadWhileScrolled++;
        });
        if (m.type == 'system') {
          setState(() => _someoneRequestedDelete = true);
          _loadRoom().then((_) {
            if (mounted) setState(() => _someoneRequestedDelete = false);
          });
        }
      }
    } else if (msg['type'] == 'status_update') {
      _applyStatusUpdate(msg);
    } else if (msg['type'] == 'reaction') {
      _applyReactionUpdate(msg);
    } else if (msg['type'] == 'message_edited') {
      final m = Message.fromJson(msg['message']);
      setState(() {
        final idx = _messages.indexWhere((e) => e.id == m.id);
        if (idx != -1) _messages[idx] = m;
      });
    } else if (msg['type'] == 'message_deleted') {
      final msgId = msg['message_id'] as int?;
      if (msgId != null) setState(() => _messages.removeWhere((e) => e.id == msgId));
    } else if (msg['type'] == 'typing') {
      final userId = msg['user_id'] as int?;
      final isTyping = msg['typing'] as bool? ?? false;
      if (userId == null || userId == AuthService().userId) return;
      _typingTimers[userId]?.cancel();
      setState(() {
        if (isTyping) _typingUsers.add(userId);
        else _typingUsers.remove(userId);
      });
      if (isTyping) {
        _typingTimers[userId] = Timer(const Duration(seconds: 3), () {
          if (mounted) setState(() => _typingUsers.remove(userId));
        });
      }
    } else if (msg['type'] == 'member_left') {
      _loadRoom();
    } else if (msg['type'] == 'delete_request') {
      final m = msg['message'] != null ? Message.fromJson(msg['message']) : null;
      setState(() {
        if (m != null && !_messages.any((e) => e.id == m.id)) {
          _messages.insert(0, m);
        }
        _someoneRequestedDelete = true;
      });
      _loadRoom();
    }
  }

  void _applyStatusUpdate(Map<String, dynamic> msg) {
    final messageId = msg['message_id'] as int?;
    final userId    = msg['user_id']    as int?;
    if (messageId == null || userId == null) return;
    final deliveredAt = msg['delivered_at'] != null ? DateTime.tryParse(msg['delivered_at'] as String) : null;
    final readAt      = msg['read_at']      != null ? DateTime.tryParse(msg['read_at']      as String) : null;
    setState(() {
      final idx = _messages.indexWhere((m) => m.id == messageId);
      if (idx == -1) return;
      final old = _messages[idx];
      final deliveries = List<MessageDelivery>.from(old.deliveries);
      final dIdx = deliveries.indexWhere((d) => d.userId == userId);
      if (dIdx != -1) {
        deliveries[dIdx] = deliveries[dIdx].copyWith(
          deliveredAt: deliveredAt,
          readAt: readAt,
        );
      } else {
        deliveries.add(MessageDelivery(userId: userId, deliveredAt: deliveredAt, readAt: readAt));
      }
      _messages[idx] = old.copyWith(deliveries: deliveries);
    });
  }

  void _applyReactionUpdate(Map<String, dynamic> msg) {
    final messageId = msg['message_id'] as int?;
    if (messageId == null) return;
    final rawReactions = msg['reactions'] as List?;
    if (rawReactions == null) return;
    final reactions = rawReactions.map((e) => MessageReaction.fromJson(e as Map<String, dynamic>)).toList();
    setState(() {
      final idx = _messages.indexWhere((m) => m.id == messageId);
      if (idx != -1) _messages[idx] = _messages[idx].copyWith(reactions: reactions);
    });
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
    WsService().sendTyping(widget.room.id, false);

    // Szerkesztés mód
    if (_editingMessage != null) {
      final editing = _editingMessage!;
      setState(() { _sending = true; _editingMessage = null; });
      try {
        final m = await ApiService().editMessage(widget.room.id, editing.id, text);
        setState(() {
          final idx = _messages.indexWhere((e) => e.id == m.id);
          if (idx != -1) _messages[idx] = m;
        });
      } catch (e) {
        if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
      } finally {
        if (mounted) setState(() => _sending = false);
      }
      return;
    }

    final replyId = _replyTo?.id;
    final mentionAll = _mentionAll;
    final mentionIds = List<int>.from(_pendingMentionIds);
    setState(() { _sending = true; _replyTo = null; _mentionAll = false; _pendingMentionIds.clear(); });
    try {
      final m = await ApiService().sendMessage(
        widget.room.id,
        type: 'text',
        content: text,
        replyToId: replyId,
        mentionAll: mentionAll,
        mentionUserIds: mentionIds.isEmpty ? null : mentionIds,
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

  Future<void> _deleteMessage(Message message) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Üzenet törlése'),
        content: const Text('Biztosan törlöd ezt az üzenetet? Mindenkinél eltűnik.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Mégsem')),
          TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Törlés', style: TextStyle(color: Colors.red))),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await ApiService().deleteMessage(_room.id, message.id);
      setState(() => _messages.removeWhere((m) => m.id == message.id));
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
    }
  }

  void _startEdit(Message message) {
    setState(() {
      _editingMessage = message;
      _replyTo = null;
    });
    _msgCtrl.text = message.content ?? '';
    _msgCtrl.selection = TextSelection.fromPosition(TextPosition(offset: _msgCtrl.text.length));
  }

  Future<void> _pickImage() async {
    final picker = ImagePicker();
    final picked = await picker.pickImage(source: ImageSource.gallery, imageQuality: 85);
    if (picked == null) return;
    final size = await File(picked.path).length();
    final ok = await _confirmSend(picked.name, 'kép', size);
    if (ok != true) return;
    await _uploadAndSend(File(picked.path), 'image');
  }

  Future<void> _pickFile() async {
    final result = await FilePicker.platform.pickFiles();
    if (result == null || result.files.single.path == null) return;
    final size = await File(result.files.single.path!).length();
    final ok = await _confirmSend(result.files.single.name, 'fájl', size);
    if (ok != true) return;
    await _uploadAndSend(File(result.files.single.path!), 'file');
  }

  Future<bool?> _confirmSend(String name, String label, int size) => showDialog<bool>(
    context: context,
    builder: (_) => AlertDialog(
      title: Text('$label küldése'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(name, style: const TextStyle(fontWeight: FontWeight.w500)),
          const SizedBox(height: 4),
          Text(_formatBytes(size), style: const TextStyle(fontSize: 12, color: Colors.grey)),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Mégsem')),
        TextButton(onPressed: () => Navigator.pop(context, true), child: const Text('Küldés')),
      ],
    ),
  );

  Future<void> _uploadAndSend(File file, String type) async {
    final fileSize = await file.length();
    setState(() => _sending = true);
    try {
      final uploaded = await ApiService().uploadFile(file);
      final m = await ApiService().sendMessage(
        widget.room.id,
        type: type,
        fileUrl: uploaded['url'] ?? uploaded['file_url'],
        fileName: uploaded['file_name'],
        fileSize: fileSize,
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

  void _showEmojiPicker(Message message) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: Text('Emoji', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            ),
            GridView.builder(
              shrinkWrap: true,
              padding: const EdgeInsets.fromLTRB(8, 0, 8, 16),
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 8, childAspectRatio: 1),
              itemCount: _kEmojis.length,
              itemBuilder: (_, i) => GestureDetector(
                onTap: () { Navigator.pop(context); _toggleReaction(message, _kEmojis[i]); },
                child: Center(child: Text(_kEmojis[i], style: const TextStyle(fontSize: 24))),
              ),
            ),
          ],
        ),
      ),
    );
  }

  void _showReactionDetails(Message message) {
    const serverBase = 'https://192.168.16.22:9456';
    final items = <(User, String)>[];
    for (final reaction in message.reactions) {
      for (final userId in reaction.userIds) {
        final user = _room.members.firstWhere(
          (m) => m.id == userId,
          orElse: () => User(id: userId, name: 'Ismeretlen', email: ''),
        );
        items.add((user, reaction.emoji));
      }
    }
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: Text('Reakciók', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            ),
            ...items.map((item) {
              final user = item.$1;
              final emoji = item.$2;
              final online = WsService().onlineUsers.contains(user.id);
              final borderColor = online ? const Color(0xFF4CAF50) : Colors.grey.shade400;
              return ListTile(
                leading: GestureDetector(
                  onTap: () => showAvatarDialog(context, user.name, user.avatarUrl),
                  child: Container(
                    padding: const EdgeInsets.all(2.5),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: borderColor,
                      boxShadow: [BoxShadow(color: borderColor.withOpacity(0.5), blurRadius: 4, spreadRadius: 1)],
                    ),
                    child: CircleAvatar(
                      radius: 16,
                      backgroundColor: kBlue,
                      backgroundImage: user.avatarUrl != null
                          ? CachedNetworkImageProvider('$serverBase${user.avatarUrl}')
                          : null,
                      child: user.avatarUrl == null
                          ? Text(user.name.isNotEmpty ? user.name[0].toUpperCase() : '?',
                              style: const TextStyle(color: Colors.white, fontSize: 12))
                          : null,
                    ),
                  ),
                ),
                title: Text(user.name),
                trailing: Text(emoji, style: const TextStyle(fontSize: 20)),
              );
            }),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  void _showMessageActions(Message message, bool isMine) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const SizedBox(height: 8),
            // Emoji reakciók
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                children: [...['👍', '❤️', '😂', '😮', '😢', '🔥'].map((emoji) {
                  final reaction = message.reactions.where((r) => r.emoji == emoji).firstOrNull;
                  final mine = reaction?.mine ?? false;
                  return GestureDetector(
                    onTap: () {
                      Navigator.pop(context);
                      _toggleReaction(message, emoji);
                    },
                    child: Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: mine ? kBlue.withOpacity(0.15) : Colors.grey.shade100,
                        borderRadius: BorderRadius.circular(12),
                        border: mine ? Border.all(color: kBlue, width: 1.5) : null,
                      ),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(emoji, style: const TextStyle(fontSize: 24)),
                          if (reaction != null && reaction.count > 0)
                            Text('${reaction.count}', style: const TextStyle(fontSize: 11)),
                        ],
                      ),
                    ),
                  );
                }),
                GestureDetector(
                  onTap: () { Navigator.pop(context); _showEmojiPicker(message); },
                  child: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(color: Colors.grey.shade100, borderRadius: BorderRadius.circular(12)),
                    child: const Icon(Icons.add_reaction_outlined, size: 24, color: Colors.grey),
                  ),
                )],
              ),
            ),
            const Divider(),
            ListTile(
              leading: const Icon(Icons.reply),
              title: const Text('Válasz'),
              onTap: () { Navigator.pop(context); setState(() => _replyTo = message); },
            ),
            if (message.type == 'text' || message.type == 'link')
              ListTile(
                leading: const Icon(Icons.copy_outlined),
                title: const Text('Másolás'),
                onTap: () {
                  Navigator.pop(context);
                  Clipboard.setData(ClipboardData(text: message.content ?? ''));
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Vágólapra másolva.'), duration: Duration(seconds: 1)),
                  );
                },
              ),
            if (isMine && message.deliveries.isNotEmpty)
              ListTile(
                leading: const Icon(Icons.people_outline),
                title: const Text('Kézbesítés részletei'),
                onTap: () { Navigator.pop(context); _showDeliveryDetails(message); },
              ),
            if (!_room.isDirect && _isAdmin)
              ListTile(
                leading: const Icon(Icons.push_pin, color: kBlue),
                title: const Text('Kiemelés'),
                onTap: () async {
                  Navigator.pop(context);
                  try {
                    await ApiService().pinMessage(_room.id, message.id);
                    await _loadRoom();
                    if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Üzenet kiemelve.')));
                  } catch (e) {
                    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
                  }
                },
              ),
            if (isMine && (message.type == 'text' || message.type == 'link'))
              ListTile(
                leading: const Icon(Icons.edit_outlined, color: kBlue),
                title: const Text('Szerkesztés'),
                onTap: () { Navigator.pop(context); _startEdit(message); },
              ),
            if (isMine)
              ListTile(
                leading: const Icon(Icons.delete_outline, color: Colors.red),
                title: const Text('Törlés', style: TextStyle(color: Colors.red)),
                onTap: () { Navigator.pop(context); _deleteMessage(message); },
              ),
          ],
        ),
      ),
    );
  }

  Future<void> _toggleReaction(Message message, String emoji) async {
    try {
      final result = await ApiService().toggleReaction(_room.id, message.id, emoji);
      final reactions = (result['reactions'] as List?)
          ?.map((e) => MessageReaction.fromJson(e as Map<String, dynamic>))
          .toList() ?? [];
      setState(() {
        final idx = _messages.indexWhere((m) => m.id == message.id);
        if (idx != -1) _messages[idx] = _messages[idx].copyWith(reactions: reactions);
      });
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
    }
  }

  void _showDeliveryDetails(Message message) {
    const serverBase = 'https://192.168.16.22:9456';
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Padding(
              padding: EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: Text('Kézbesítés részletei', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
            ),
            ..._room.members
                .where((u) => u.id != AuthService().userId)
                .map((u) {
              final d = message.deliveries.where((d) => d.userId == u.id).firstOrNull;
              final online = WsService().onlineUsers.contains(u.id);
              final borderColor = online ? const Color(0xFF4CAF50) : Colors.grey.shade400;
              final icon = d?.readAt != null
                  ? const Icon(Icons.done_all, color: Colors.green, size: 18)
                  : d?.deliveredAt != null
                      ? const Icon(Icons.done_all, color: Colors.amber, size: 18)
                      : const Icon(Icons.done, color: Colors.grey, size: 18);
              final label = d?.readAt != null
                  ? 'Elolvasta ${_fmtFull(d!.readAt!)}'
                  : d?.deliveredAt != null
                      ? 'Megkapta ${_fmtFull(d!.deliveredAt!)}'
                      : 'Nem érkezett meg';
              return ListTile(
                leading: GestureDetector(
                  onTap: () => showAvatarDialog(context, u.name, u.avatarUrl),
                  child: Container(
                    padding: const EdgeInsets.all(2.5),
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: borderColor,
                      boxShadow: [BoxShadow(color: borderColor.withOpacity(0.5), blurRadius: 4, spreadRadius: 1)],
                    ),
                    child: CircleAvatar(
                      radius: 16,
                      backgroundColor: kBlue,
                      backgroundImage: u.avatarUrl != null
                          ? CachedNetworkImageProvider('$serverBase${u.avatarUrl}')
                          : null,
                      child: u.avatarUrl == null
                          ? Text(u.name.isNotEmpty ? u.name[0].toUpperCase() : '?',
                              style: const TextStyle(color: Colors.white, fontSize: 12))
                          : null,
                    ),
                  ),
                ),
                title: Text(u.name),
                subtitle: Text(label, style: const TextStyle(fontSize: 12)),
                trailing: icon,
              );
            }),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  String _fmtFull(DateTime dt) {
    final l = dt.toLocal();
    return '${l.month}.${l.day}. ${l.hour.toString().padLeft(2,'0')}:${l.minute.toString().padLeft(2,'0')}';
  }

  void _showRoomInfo() async {
    await _loadRoom(); // frissítjük a taglistát megnyitás előtt
    if (!mounted) return;
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
    final label = forEveryone ? 'Törlés mindenkinél' : 'Törlés csak nálam';
    final desc = forEveryone
        ? 'A beszélgetés azonnal eltűnik nálad. A másik fél értesítést kap és dönthet, hogy megtartja-e.'
        : 'A beszélgetés eltűnik a listádból. A másik félnél megmarad.';
    showDialog(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(label),
        content: Text(desc),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Mégsem')),
          TextButton(
            onPressed: () async {
              Navigator.pop(context);
              try {
                if (forEveryone) {
                  await ApiService().requestDelete(_room.id); // értesíti B-t
                }
                await ApiService().hideRoom(_room.id); // A-nál azonnal eltűnik
                if (mounted) Navigator.pop(context);
              } catch (e) {
                if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.toString())));
              }
            },
            child: Text('Törlés', style: const TextStyle(color: Colors.red)),
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
        title: _room.isDirect
            ? Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(_title),
                  const SizedBox(width: 6),
                  PresenceDot(userId: _room.otherUserId(AuthService().userId ?? 0)),
                ],
              )
            : Text(_title),
        actions: [
          const WsDot(),
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
          if (_someoneRequestedDelete || (_room.deleteRequestedBy != null && _room.deleteRequestedBy != AuthService().userId))
            _DeleteRequestBanner(
              onKeep: () async {
                try {
                  await ApiService().keepRoom(_room.id);
                  setState(() => _someoneRequestedDelete = false);
                  await _loadRoom();
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
                : Stack(
                    children: [
                      Center(
                        child: Opacity(
                          opacity: 0.06,
                          child: Image.asset('assets/logo.png',
                              width: double.infinity, fit: BoxFit.fitWidth),
                        ),
                      ),
                      if (_showScrollDown)
                        Positioned(
                          bottom: 12,
                          right: 12,
                          child: GestureDetector(
                            onTap: () {
                              _scroll.animateTo(0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
                              setState(() => _unreadWhileScrolled = 0);
                            },
                            child: Stack(
                              clipBehavior: Clip.none,
                              children: [
                                Container(
                                  padding: const EdgeInsets.all(10),
                                  decoration: BoxDecoration(
                                    color: kBlue,
                                    shape: BoxShape.circle,
                                    boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 6, offset: Offset(0, 2))],
                                  ),
                                  child: const Icon(Icons.keyboard_arrow_down, color: Colors.white, size: 22),
                                ),
                                if (_unreadWhileScrolled > 0)
                                  Positioned(
                                    top: -4,
                                    right: -4,
                                    child: Container(
                                      padding: const EdgeInsets.all(4),
                                      decoration: const BoxDecoration(color: Colors.red, shape: BoxShape.circle),
                                      child: Text('$_unreadWhileScrolled',
                                          style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold)),
                                    ),
                                  ),
                              ],
                            ),
                          ),
                        ),
                      NotificationListener<ScrollNotification>(
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
                      itemBuilder: (_, i) {
                        final msg = _messages[i];
                        final isMine = msg.userId != null && msg.userId == AuthService().userId;
                        final isMentioned = !isMine &&
                            (msg.mentionAll || msg.mentionUserIds.contains(AuthService().userId));
                        return _MessageBubble(
                          message: msg,
                          isMine: isMine,
                          isGroup: !_room.isDirect,
                          isPinned: _room.pinnedMessage?.id == msg.id,
                          isMentioned: isMentioned,
                          onLongPress: () => _showMessageActions(msg, isMine),
                          onReactionTap: (emoji) => _toggleReaction(msg, emoji),
                          onReactionLongPress: msg.reactions.isNotEmpty
                              ? () => _showReactionDetails(msg)
                              : null,
                        );
                      },
                    ),
                  ),
                    ],
                  ),
          ),
          if (_editingMessage != null)
            _EditBar(
              message: _editingMessage!,
              onCancel: () { setState(() { _editingMessage = null; _msgCtrl.clear(); }); },
            ),
          if (_replyTo != null)
            _ReplyBar(
              message: _replyTo!,
              onCancel: () => setState(() => _replyTo = null),
            ),
          if (_typingUsers.isNotEmpty)
            _TypingIndicator(
              names: _typingUsers.map((id) => _room.members
                  .firstWhere((m) => m.id == id, orElse: () => User(id: id, name: '...', email: ''))
                  .name).toList(),
            ),
          if (_showMentionPicker)
            _MentionSuggestionBar(
              suggestions: _getMentionSuggestions(),
              showAll: 'all'.startsWith(_mentionQuery.toLowerCase()),
              onSelect: _selectMention,
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
  StreamSubscription? _presSub;

  @override
  void initState() {
    super.initState();
    _presSub = WsService().events.listen((msg) {
      final type = msg['type'] as String?;
      if ((type == 'presence' || type == 'presence_list') && mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _presSub?.cancel();
    super.dispose();
  }

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
          ...widget.room.members.map((u) {
            const serverBase = 'https://192.168.16.22:9456';
            final online = WsService().onlineUsers.contains(u.id);
            final borderColor = online ? const Color(0xFF4CAF50) : Colors.grey.shade400;
            return ListTile(
              contentPadding: EdgeInsets.zero,
              leading: GestureDetector(
                onTap: () => showAvatarDialog(context, u.name, u.avatarUrl),
                child: Container(
                  padding: const EdgeInsets.all(3),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: borderColor,
                    boxShadow: [BoxShadow(color: borderColor.withOpacity(0.5), blurRadius: 5, spreadRadius: 1)],
                  ),
                  child: CircleAvatar(
                    backgroundColor: kBlue,
                    backgroundImage: u.avatarUrl != null
                        ? CachedNetworkImageProvider('$serverBase${u.avatarUrl}')
                        : null,
                    child: u.avatarUrl == null
                        ? Text(u.name.isNotEmpty ? u.name[0].toUpperCase() : '?',
                            style: const TextStyle(color: Colors.white))
                        : null,
                  ),
                ),
              ),
              title: Text(u.name),
              subtitle: Text(
                online ? 'Online' : 'Offline',
                style: TextStyle(color: online ? const Color(0xFF4CAF50) : Colors.grey, fontSize: 12),
              ),
              trailing: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  PresenceDot(userId: u.id),
                  const SizedBox(width: 4),
                  if (u.id != me)
                    IconButton(
                      icon: const Icon(Icons.message_outlined, color: kBlue),
                      tooltip: 'Üzenet küldése',
                      onPressed: () => widget.onDirectMessage(u.id),
                    )
                  else
                    const Padding(
                      padding: EdgeInsets.symmetric(horizontal: 8),
                      child: Text('(én)', style: TextStyle(color: Colors.grey, fontSize: 12)),
                    ),
                ],
              ),
            );
          }),
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
  // ignore: unused_element
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
      child: Row(
        children: [
          const Icon(Icons.warning_amber, size: 16, color: Colors.orange),
          const SizedBox(width: 8),
          const Expanded(child: Text('A másik fél törölte ezt a beszélgetést.',
              style: TextStyle(fontSize: 13, color: Colors.orange))),
          TextButton(onPressed: onKeep, child: const Text('Megtartom')),
          TextButton(
            onPressed: onDelete,
            child: const Text('Én is törlöm', style: TextStyle(color: Colors.red)),
          ),
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
  final bool isPinned;
  final bool isMentioned;
  final VoidCallback? onLongPress;
  final void Function(String emoji)? onReactionTap;
  final VoidCallback? onReactionLongPress;
  const _MessageBubble({
    required this.message,
    required this.isMine,
    required this.isGroup,
    this.isPinned = false,
    this.isMentioned = false,
    this.onLongPress,
    this.onReactionTap,
    this.onReactionLongPress,
  });

  @override
  Widget build(BuildContext context) {
    if (message.type == 'system') {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 24),
        child: Center(child: Text(message.content ?? '', style: const TextStyle(color: Colors.grey, fontStyle: FontStyle.italic, fontSize: 12), textAlign: TextAlign.center)),
      );
    }
    return GestureDetector(
      onLongPress: onLongPress,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            if (!isMine) ...[
              const SizedBox(width: 8),
              GestureDetector(
                onTap: () => showAvatarDialog(context, message.userName, message.avatarUrl),
                child: _MiniAvatar(avatarUrl: message.avatarUrl, name: message.userName, userId: message.userId),
              ),
              const SizedBox(width: 6),
            ],
            Expanded(
              child: Align(
                alignment: isMine ? Alignment.centerRight : Alignment.centerLeft,
                child: ConstrainedBox(
                  constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.75),
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
                  border: isMine ? null : Border.all(color: Colors.grey.shade300, width: 1),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (message.replyTo != null)
                      _ReplyQuoteBox(replyTo: message.replyTo!, isMine: isMine),
                    if (isMentioned)
                      _MentionBadge(isAll: message.mentionAll),
                    _buildContent(context),
                  ],
                ),
              ),
              // Reakciók
              if (message.reactions.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Wrap(
                    spacing: 4,
                    children: message.reactions.map((r) => GestureDetector(
                      onTap: () => onReactionTap?.call(r.emoji),
                      onLongPress: onReactionLongPress,
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: r.mine ? kBlue.withOpacity(0.15) : Colors.grey.shade200,
                          border: r.mine ? Border.all(color: kBlue, width: 1) : null,
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Text('${r.emoji} ${r.count}', style: const TextStyle(fontSize: 12)),
                      ),
                    )).toList(),
                  ),
                ),
              // Idő + delivery ikon
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (isPinned) ...[
                    const Icon(Icons.push_pin, size: 10, color: kBlue),
                    const SizedBox(width: 2),
                  ],
                  Padding(
                    padding: const EdgeInsets.only(top: 2, left: 4, right: 4),
                    child: Text(
                      '${_formatTime(message.createdAt)}${message.isEdited ? ' · szerk.' : ''}',
                      style: const TextStyle(fontSize: 10, color: Colors.grey),
                    ),
                  ),
                  if (isMine) ...[
                    const SizedBox(width: 2),
                    _DeliveryIcon(deliveries: message.deliveries),
                  ],
                ],
              ),
                    ],
                  ),
                ),
              ),
            ),
            if (isMine) const SizedBox(width: 8),
          ],
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
        return _FileBubble(fileName: message.fileName ?? 'Fájl', fileUrl: message.fileUrl ?? '', isMine: isMine, fileSize: message.fileSize);
      case 'link':
        return GestureDetector(
          onTap: () => _confirmOpenLink(context, message.content ?? ''),
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

String _formatBytes(int bytes) {
  if (bytes < 1024) return '$bytes B';
  if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(1)} KB';
  return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
}

void _confirmOpenLink(BuildContext context, String url) {
  showDialog(
    context: context,
    builder: (_) => AlertDialog(
      title: const Text('Link megnyitása'),
      content: Text(url, maxLines: 3, overflow: TextOverflow.ellipsis,
          style: const TextStyle(fontSize: 13, color: Colors.black54)),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Mégsem')),
        TextButton(
          onPressed: () {
            Navigator.pop(context);
            launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
          },
          child: const Text('Megnyitás'),
        ),
      ],
    ),
  );
}


// Kép buborék — bélyegkép + letöltés
class _ImageBubble extends StatelessWidget {
  final String fileUrl;
  final bool isMine;
  static const String _serverBase = 'https://192.168.16.22:9456';
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
            width: MediaQuery.of(context).size.width * 0.65,
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

class _MiniAvatar extends StatelessWidget {
  final String? avatarUrl;
  final String name;
  final int? userId;
  static const String _serverBase = 'https://192.168.16.22:9456';
  const _MiniAvatar({required this.avatarUrl, required this.name, this.userId});

  @override
  Widget build(BuildContext context) {
    final url = avatarUrl != null ? '$_serverBase$avatarUrl' : null;
    final isOnline = userId != null && WsService().onlineUsers.contains(userId);
    final borderColor = isOnline ? const Color(0xFF4CAF50) : Colors.grey.shade400;
    return Stack(
      children: [
        Container(
          padding: const EdgeInsets.all(3),
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: borderColor,
            boxShadow: [BoxShadow(color: borderColor.withOpacity(0.5), blurRadius: 6, spreadRadius: 1)],
          ),
          child: CircleAvatar(
            radius: 12,
            backgroundColor: kBlue,
            backgroundImage: url != null ? CachedNetworkImageProvider(url) : null,
            child: url == null
                ? Text(name.isEmpty ? '?' : name[0].toUpperCase(),
                    style: const TextStyle(fontSize: 10, color: Colors.white, fontWeight: FontWeight.bold))
                : null,
          ),
        ),
      ],
    );
  }
}

// Fájl buborék — név + méret + típus ikon + letöltés
class _FileBubble extends StatelessWidget {
  final String fileName;
  final String fileUrl;
  final bool isMine;
  final int? fileSize;
  static const String _serverBase = 'https://192.168.16.22:9456';
  const _FileBubble({required this.fileName, required this.fileUrl, required this.isMine, this.fileSize});

  String get _sizeLabel => fileSize != null ? _formatBytes(fileSize!) : '';

  IconData _typeIcon() {
    final ext = fileName.contains('.') ? fileName.split('.').last.toLowerCase() : '';
    switch (ext) {
      case 'pdf':                          return Icons.picture_as_pdf;
      case 'doc': case 'docx':            return Icons.description;
      case 'xls': case 'xlsx':            return Icons.table_chart;
      case 'ppt': case 'pptx':            return Icons.slideshow;
      case 'zip': case 'rar': case '7z':  return Icons.folder_zip;
      case 'mp3': case 'wav': case 'aac': return Icons.audio_file;
      case 'mp4': case 'mov': case 'avi': return Icons.video_file;
      case 'txt':                          return Icons.text_snippet;
      default:                             return Icons.insert_drive_file_outlined;
    }
  }

  @override
  Widget build(BuildContext context) {
    final color = isMine ? Colors.white : kBlue;
    return GestureDetector(
      onTap: () => _open(context),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(_typeIcon(), color: color, size: 22),
          const SizedBox(width: 8),
          Flexible(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(fileName, style: TextStyle(color: isMine ? Colors.white : Colors.black87)),
                if (_sizeLabel.isNotEmpty)
                  Text(_sizeLabel, style: TextStyle(fontSize: 11, color: isMine ? Colors.white60 : Colors.grey)),
              ],
            ),
          ),
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

// ✓ / ✓✓ delivery ikon — piros=elküldve, sárga=megkapta, zöld=olvasta
class _DeliveryIcon extends StatelessWidget {
  final List<MessageDelivery> deliveries;
  const _DeliveryIcon({required this.deliveries});

  @override
  Widget build(BuildContext context) {
    if (deliveries.isEmpty) {
      return const Icon(Icons.done, size: 14, color: Colors.white54);
    }
    final allRead = deliveries.every((d) => d.readAt != null);
    final allDelivered = deliveries.every((d) => d.deliveredAt != null);
    final color = allRead
        ? const Color(0xFF66BB6A)
        : allDelivered
            ? const Color(0xFFFFD54F)
            : const Color(0xFFEF9A9A);
    return Icon(
      allDelivered || allRead ? Icons.done_all : Icons.done,
      size: 14,
      color: color,
    );
  }
}

// Idézet doboz a buborékon belül
class _ReplyQuoteBox extends StatelessWidget {
  final ReplyTo replyTo;
  final bool isMine;
  const _ReplyQuoteBox({required this.replyTo, required this.isMine});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      decoration: BoxDecoration(
        color: isMine ? Colors.white.withOpacity(0.2) : Colors.black.withOpacity(0.07),
        borderRadius: BorderRadius.circular(8),
        border: Border(left: BorderSide(color: isMine ? Colors.white54 : kBlue, width: 3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(replyTo.userName, style: TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: isMine ? Colors.white70 : kBlue)),
          const SizedBox(height: 2),
          Text(
            replyTo.content,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(fontSize: 12, color: isMine ? Colors.white70 : Colors.black54),
          ),
        ],
      ),
    );
  }
}

// Reply sáv az input felett
class _ReplyBar extends StatelessWidget {
  final Message message;
  final VoidCallback onCancel;
  const _ReplyBar({required this.message, required this.onCancel});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      color: kBlue.withOpacity(0.08),
      child: Row(
        children: [
          const Icon(Icons.reply, size: 16, color: kBlue),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(message.userName, style: const TextStyle(fontSize: 11, color: kBlue, fontWeight: FontWeight.bold)),
                Text(
                  message.content ?? message.fileName ?? '',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 12, color: Colors.black54),
                ),
              ],
            ),
          ),
          IconButton(icon: const Icon(Icons.close, size: 18), onPressed: onCancel, padding: EdgeInsets.zero),
        ],
      ),
    );
  }
}

class _EditBar extends StatelessWidget {
  final Message message;
  final VoidCallback onCancel;
  const _EditBar({required this.message, required this.onCancel});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      color: Colors.orange.withOpacity(0.08),
      child: Row(
        children: [
          const Icon(Icons.edit_outlined, size: 16, color: Colors.orange),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message.content ?? '',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 12, color: Colors.black54),
            ),
          ),
          IconButton(icon: const Icon(Icons.close, size: 18), onPressed: onCancel, padding: EdgeInsets.zero),
        ],
      ),
    );
  }
}

const _kEmojis = [
  '👍','👎','❤️','🔥','😂','😮','😢','😍',
  '🥰','🤩','😎','🥳','🤔','😅','🤣','😁',
  '😊','😭','😤','🤯','🫠','😬','🙄','😴',
  '👏','🙌','🤝','🫶','👋','✌️','💪','🤌',
  '🧡','💛','💚','💙','💜','🖤','🤍','💔',
  '🎉','🏆','🎯','💯','⭐','✨','💫','⚡',
  '🚀','💡','💎','🌟','✅','❌','❗','❓',
];

// Gépelés jelző
class _TypingIndicator extends StatelessWidget {
  final List<String> names;
  const _TypingIndicator({required this.names});

  @override
  Widget build(BuildContext context) {
    final text = names.length == 1
        ? '${names[0]} gépel...'
        : '${names.join(' és ')} gépelnek...';
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
      child: Text(text,
          style: TextStyle(fontSize: 12, color: Colors.grey.shade600, fontStyle: FontStyle.italic)),
    );
  }
}

// @ mention badge — piros ! vagy !all az üzenet buborékon belül
class _MentionBadge extends StatelessWidget {
  final bool isAll;
  const _MentionBadge({required this.isAll});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 4),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color: Colors.red,
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        isAll ? '!all' : '!',
        style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
      ),
    );
  }
}

// @ taggelés javaslatlista az input felett
class _MentionSuggestionBar extends StatelessWidget {
  final List<User> suggestions;
  final bool showAll;
  final void Function(User?) onSelect;
  const _MentionSuggestionBar({required this.suggestions, required this.showAll, required this.onSelect});

  @override
  Widget build(BuildContext context) {
    final items = <Widget>[];
    if (showAll) {
      items.add(ListTile(
        dense: true,
        leading: const CircleAvatar(radius: 14, backgroundColor: kLime, child: Icon(Icons.group, color: Colors.white, size: 16)),
        title: const Text('@all', style: TextStyle(fontWeight: FontWeight.w600)),
        subtitle: const Text('Mindenki', style: TextStyle(fontSize: 11)),
        onTap: () => onSelect(null),
      ));
    }
    for (final u in suggestions) {
      items.add(ListTile(
        dense: true,
        leading: CircleAvatar(
          radius: 14,
          backgroundColor: kBlue,
          child: Text(
            u.name.isNotEmpty ? u.name[0].toUpperCase() : '?',
            style: const TextStyle(color: Colors.white, fontSize: 11),
          ),
        ),
        title: Text('@${u.name}'),
        onTap: () => onSelect(u),
      ));
    }
    if (items.isEmpty) return const SizedBox.shrink();
    return Container(
      constraints: const BoxConstraints(maxHeight: 180),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: Colors.grey.shade200)),
        boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4, offset: Offset(0, -2))],
      ),
      child: ListView(shrinkWrap: true, children: items),
    );
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
