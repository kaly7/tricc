import 'dart:io';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:image_picker/image_picker.dart';
import 'package:file_picker/file_picker.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:http/http.dart' as http;
import '../models/room.dart';
import '../models/message.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/ws_service.dart';

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
  bool _loading = true;
  bool _sending = false;
  bool _hasMore = true;

  @override
  void initState() {
    super.initState();
    _loadMessages();
    WsService().join(widget.room.id);
    WsService().events.listen(_onWsEvent);
  }

  @override
  void dispose() {
    WsService().leave(widget.room.id);
    _msgCtrl.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _onWsEvent(Map<String, dynamic> msg) {
    if (!mounted) return;
    if (msg['type'] == 'message' && msg['room_id'] == widget.room.id) {
      final m = Message.fromJson(msg['message']);
      if (!_messages.any((e) => e.id == m.id)) {
        setState(() => _messages.insert(0, m));
      }
    }
  }

  Future<void> _loadMessages({bool older = false}) async {
    final before = older && _messages.isNotEmpty ? _messages.last.id : null;
    try {
      final msgs = await ApiService().getMessages(widget.room.id, before: before);
      setState(() {
        if (older) {
          _messages.addAll(msgs);
        } else {
          _messages.clear();
          _messages.addAll(msgs);
        }
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
        fileUrl: uploaded['url'],
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.room.name)),
      body: Column(
        children: [
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
                        isMine: _messages[i].userId == AuthService().userId,
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

class _MessageBubble extends StatelessWidget {
  final Message message;
  final bool isMine;
  const _MessageBubble({required this.message, required this.isMine});

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: isMine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        margin: EdgeInsets.only(
          top: 4, bottom: 4,
          left: isMine ? 64 : 12,
          right: isMine ? 12 : 64,
        ),
        child: Column(
          crossAxisAlignment: isMine ? CrossAxisAlignment.end : CrossAxisAlignment.start,
          children: [
            if (!isMine)
              Padding(
                padding: const EdgeInsets.only(left: 4, bottom: 2),
                child: Text(message.userName, style: const TextStyle(fontSize: 12, color: Colors.grey, fontWeight: FontWeight.w600)),
              ),
            Container(
              padding: _needsPadding ? const EdgeInsets.symmetric(horizontal: 12, vertical: 8) : EdgeInsets.zero,
              decoration: BoxDecoration(
                color: isMine ? const Color(0xFF1A73E8) : const Color(0xFFEEEEEE),
                borderRadius: BorderRadius.only(
                  topLeft: const Radius.circular(16),
                  topRight: const Radius.circular(16),
                  bottomLeft: Radius.circular(isMine ? 16 : 4),
                  bottomRight: Radius.circular(isMine ? 4 : 16),
                ),
              ),
              child: _buildContent(context),
            ),
            Padding(
              padding: const EdgeInsets.only(top: 2, left: 4, right: 4),
              child: Text(_formatTime(message.createdAt), style: const TextStyle(fontSize: 10, color: Colors.grey)),
            ),
          ],
        ),
      ),
    );
  }

  bool get _needsPadding => message.type == 'text' || message.type == 'link' || message.type == 'file';

  Widget _buildContent(BuildContext context) {
    switch (message.type) {
      case 'text':
        return Text(message.content ?? '', style: TextStyle(color: isMine ? Colors.white : Colors.black87));
      case 'image':
        return ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: CachedNetworkImage(
            imageUrl: 'http://192.168.16.22:9453${message.fileUrl}',
            width: 220,
            fit: BoxFit.cover,
            placeholder: (context, url) => const SizedBox(height: 120, child: Center(child: CircularProgressIndicator())),
          ),
        );
      case 'file':
        return GestureDetector(
          onTap: () => _openFile(context),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.insert_drive_file, color: isMine ? Colors.white : const Color(0xFF1A73E8)),
              const SizedBox(width: 8),
              Flexible(child: Text(message.fileName ?? 'Fájl', style: TextStyle(color: isMine ? Colors.white : Colors.black87))),
            ],
          ),
        );
      case 'link':
        return GestureDetector(
          onTap: () => launchUrl(Uri.parse(message.content ?? ''), mode: LaunchMode.externalApplication),
          child: Text(message.content ?? '', style: TextStyle(color: isMine ? Colors.white : Colors.blue, decoration: TextDecoration.underline)),
        );
      default:
        return const SizedBox.shrink();
    }
  }

  Future<void> _openFile(BuildContext context) async {
    if (message.fileUrl == null) return;
    final fileUrl = 'http://192.168.16.22:9453${message.fileUrl}';
    try {
      final dir = await getTemporaryDirectory();
      final path = '${dir.path}/${message.fileName ?? 'file'}';
      final res = await http.get(Uri.parse(fileUrl));
      await File(path).writeAsBytes(res.bodyBytes);
      await OpenFilex.open(path);
    } catch (_) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Nem sikerült megnyitni a fájlt.')));
      }
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
              backgroundColor: const Color(0xFF1A73E8),
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
