import 'dart:async';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import '../models/room.dart';
import '../models/message.dart';
import '../services/api_service.dart';
import '../app_theme.dart';

class RoomSearchScreen extends StatefulWidget {
  final Room room;
  const RoomSearchScreen({super.key, required this.room});

  @override
  State<RoomSearchScreen> createState() => _RoomSearchScreenState();
}

class _RoomSearchScreenState extends State<RoomSearchScreen> {
  final _ctrl = TextEditingController();
  List<Message> _results = [];
  bool _loading = false;
  bool _searched = false;
  Timer? _debounce;

  static const _serverBase = 'https://192.168.16.22:9456';

  @override
  void dispose() {
    _ctrl.dispose();
    _debounce?.cancel();
    super.dispose();
  }

  void _onChanged(String q) {
    _debounce?.cancel();
    if (q.trim().length < 2) {
      setState(() { _results = []; _searched = false; });
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 400), () => _search(q.trim()));
  }

  Future<void> _search(String q) async {
    setState(() => _loading = true);
    try {
      final results = await ApiService().searchMessages(widget.room.id, q);
      if (mounted) setState(() { _results = results; _loading = false; _searched = true; });
    } catch (e) {
      if (mounted) setState(() { _loading = false; _searched = true; });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: TextField(
          controller: _ctrl,
          autofocus: true,
          decoration: const InputDecoration(
            hintText: 'Keresés az üzenetekben...',
            border: InputBorder.none,
            contentPadding: EdgeInsets.symmetric(horizontal: 4),
          ),
          onChanged: _onChanged,
        ),
        actions: [
          if (_ctrl.text.isNotEmpty)
            IconButton(
              icon: const Icon(Icons.close),
              onPressed: () {
                _ctrl.clear();
                setState(() { _results = []; _searched = false; });
              },
            ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : !_searched
              ? const Center(child: Text('Legalább 2 karakter szükséges.', style: TextStyle(color: Colors.grey)))
              : _results.isEmpty
                  ? const Center(child: Text('Nincs találat.', style: TextStyle(color: Colors.grey)))
                  : ListView.separated(
                      itemCount: _results.length,
                      separatorBuilder: (_, __) => const Divider(height: 1),
                      itemBuilder: (_, i) {
                        final msg = _results[i];
                        return ListTile(
                          onTap: () => Navigator.pop(context, msg),
                          leading: CircleAvatar(
                            radius: 18,
                            backgroundColor: kBlue,
                            backgroundImage: msg.avatarUrl != null
                                ? CachedNetworkImageProvider('$_serverBase${msg.avatarUrl}')
                                : null,
                            child: msg.avatarUrl == null
                                ? Text(msg.userName.isNotEmpty ? msg.userName[0].toUpperCase() : '?',
                                    style: const TextStyle(color: Colors.white, fontSize: 12))
                                : null,
                          ),
                          title: Row(
                            children: [
                              Text(msg.userName, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                              const SizedBox(width: 8),
                              Text(_fmtDate(msg.createdAt),
                                  style: const TextStyle(fontSize: 11, color: Colors.grey)),
                            ],
                          ),
                          subtitle: _buildHighlighted(context, msg.content ?? '', _ctrl.text.trim()),
                          isThreeLine: false,
                        );
                      },
                    ),
    );
  }

  Widget _buildHighlighted(BuildContext context, String text, String query) {
    if (query.isEmpty) return Text(text, maxLines: 2, overflow: TextOverflow.ellipsis);
    final lower = text.toLowerCase();
    final idx = lower.indexOf(query.toLowerCase());
    if (idx < 0) return Text(text, maxLines: 2, overflow: TextOverflow.ellipsis);
    return RichText(
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
      text: TextSpan(
        style: TextStyle(color: Theme.of(context).colorScheme.onSurface, fontSize: 14),
        children: [
          if (idx > 0) TextSpan(text: text.substring(0, idx)),
          TextSpan(
            text: text.substring(idx, idx + query.length),
            style: const TextStyle(backgroundColor: Color(0xFFFFEB3B), fontWeight: FontWeight.bold),
          ),
          if (idx + query.length < text.length)
            TextSpan(text: text.substring(idx + query.length)),
        ],
      ),
    );
  }

  String _fmtDate(String iso) {
    try {
      final dt = DateTime.parse(iso).toLocal();
      return '${dt.month}.${dt.day}. ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';
    } catch (_) {
      return '';
    }
  }
}
