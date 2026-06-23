import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import '../models/room.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/share_service.dart';
import '../app_theme.dart';

class ShareModal extends StatefulWidget {
  final List<SharedMediaFile> files;
  const ShareModal({super.key, required this.files});

  static Future<void> show(BuildContext context, List<SharedMediaFile> files) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => ShareModal(files: files),
    );
  }

  @override
  State<ShareModal> createState() => _ShareModalState();
}

class _ShareModalState extends State<ShareModal> {
  final _textCtrl    = TextEditingController();
  final _searchCtrl  = TextEditingController();
  List<Room> _rooms    = [];
  List<Room> _filtered = [];
  Room? _selected;
  bool _loading = true;
  bool _sending = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (!AuthService().isLoggedIn) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) {
          Navigator.pop(context);
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Kérlek jelentkezz be a BabL42 appban')),
          );
        }
      });
      return;
    }
    _loadRooms();
    _searchCtrl.addListener(_onSearch);
  }

  @override
  void dispose() {
    _textCtrl.dispose();
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadRooms() async {
    try {
      final rooms = await ApiService().getRooms();
      if (mounted) setState(() { _rooms = rooms; _filtered = rooms; _loading = false; });
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _loading = false; });
    }
  }

  void _onSearch() {
    final q = _searchCtrl.text.toLowerCase();
    setState(() {
      _filtered = q.isEmpty
          ? _rooms
          : _rooms.where((r) =>
              r.displayName(AuthService().userId ?? 0).toLowerCase().contains(q)).toList();
    });
  }

  Future<void> _send() async {
    if (_selected == null || _sending) return;
    setState(() { _sending = true; _error = null; });
    try {
      for (final f in widget.files) {
        final result   = await ApiService().uploadFile(File(f.path));
        final fileUrl  = result['file_url'] as String;
        final fileName = result['file_name'] as String?;
        final fileSize = result['file_size'] as int?;
        final msgType  = _resolveType(f.type);
        await ApiService().sendMessage(
          _selected!.id,
          type: msgType,
          fileUrl: fileUrl,
          fileName: fileName,
          fileSize: fileSize,
        );
      }
      final text = _textCtrl.text.trim();
      if (text.isNotEmpty) {
        await ApiService().sendMessage(_selected!.id, type: 'text', content: text);
      }
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _sending = false; });
    }
  }

  String _resolveType(SharedMediaType t) {
    switch (t) {
      case SharedMediaType.image: return 'image';
      case SharedMediaType.video: return 'video';
      default:                    return 'file';
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.of(context).viewInsets.bottom;
    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.85),
      child: Padding(
        padding: EdgeInsets.fromLTRB(16, 16, 16, bottom + 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(children: [
              const Text('Megosztás', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              const Spacer(),
              IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(context)),
            ]),
            const SizedBox(height: 8),

            // Fájl előnézet sor
            SizedBox(
              height: 72,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: widget.files.length,
                separatorBuilder: (_, _) => const SizedBox(width: 8),
                itemBuilder: (_, i) => _FileTile(file: widget.files[i]),
              ),
            ),
            const SizedBox(height: 12),

            // Szoba kereső
            TextField(
              controller: _searchCtrl,
              decoration: const InputDecoration(
                hintText: 'Szoba keresése...',
                prefixIcon: Icon(Icons.search),
                isDense: true,
              ),
            ),
            const SizedBox(height: 8),

            // Szoba lista
            if (_loading)
              const Center(child: CircularProgressIndicator())
            else if (_error != null && _rooms.isEmpty)
              Text(_error!, style: const TextStyle(color: Colors.red))
            else
              Flexible(
                child: ListView.builder(
                  shrinkWrap: true,
                  itemCount: _filtered.length,
                  itemBuilder: (_, i) {
                    final room     = _filtered[i];
                    final name     = room.displayName(AuthService().userId ?? 0);
                    final selected = _selected?.id == room.id;
                    return ListTile(
                      dense: true,
                      leading: CircleAvatar(
                        backgroundColor: room.isDirect ? kBlue : kLime,
                        radius: 16,
                        child: Icon(
                          room.isDirect ? Icons.person : Icons.group,
                          size: 14,
                          color: Colors.white,
                        ),
                      ),
                      title: Text(name, style: const TextStyle(fontSize: 14)),
                      selected: selected,
                      selectedTileColor: kBlue.withAlpha(20),
                      onTap: () => setState(() => _selected = room),
                      trailing: selected
                          ? const Icon(Icons.check_circle, color: kBlue, size: 18)
                          : null,
                    );
                  },
                ),
              ),
            const SizedBox(height: 8),

            // Opcionális szöveg
            TextField(
              controller: _textCtrl,
              decoration: const InputDecoration(
                hintText: 'Üzenet (opcionális)',
                isDense: true,
              ),
              maxLines: 2,
            ),
            const SizedBox(height: 12),

            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(_error!, style: const TextStyle(color: Colors.red, fontSize: 13)),
              ),

            ElevatedButton(
              onPressed: (_selected == null || _sending) ? null : _send,
              child: _sending
                  ? const SizedBox(
                      height: 20, width: 20,
                      child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                  : Text(
                      widget.files.length > 1
                          ? 'Küldés (${widget.files.length} fájl)'
                          : 'Küldés'),
            ),
          ],
        ),
      ),
    );
  }
}

class _FileTile extends StatelessWidget {
  final SharedMediaFile file;
  const _FileTile({required this.file});

  @override
  Widget build(BuildContext context) {
    final isImage = file.type == SharedMediaType.image;
    final isVideo = file.type == SharedMediaType.video;
    final name    = file.path.split('/').last;

    return Container(
      width: 64,
      decoration: BoxDecoration(
        color: Colors.grey.shade100,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: Colors.grey.shade300),
      ),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          if (isImage)
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: Image.file(
                File(file.path),
                width: 48, height: 48, fit: BoxFit.cover,
                errorBuilder: (_, _, _) => const Icon(Icons.image, size: 32),
              ),
            )
          else
            Icon(
              isVideo ? Icons.videocam : Icons.insert_drive_file,
              size: 32,
              color: isVideo ? Colors.purple : Colors.grey,
            ),
          const SizedBox(height: 2),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Text(
              name.length > 8 ? '${name.substring(0, 6)}..' : name,
              style: const TextStyle(fontSize: 9),
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}
