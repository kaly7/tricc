import 'dart:io';
import 'package:flutter/material.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:path_provider/path_provider.dart';
import 'package:http/http.dart' as http;
import 'package:http/io_client.dart';
import 'package:open_filex/open_filex.dart';
import '../models/room.dart';
import '../models/message.dart';
import '../services/api_service.dart';
import '../app_theme.dart';

class RoomMediaScreen extends StatefulWidget {
  final Room room;
  const RoomMediaScreen({super.key, required this.room});

  @override
  State<RoomMediaScreen> createState() => _RoomMediaScreenState();
}

class _RoomMediaScreenState extends State<RoomMediaScreen> with SingleTickerProviderStateMixin {
  late final TabController _tab;
  List<Message> _images = [];
  List<Message> _files = [];
  bool _loading = true;

  static String get _serverBase => ApiService.fileBase;

  @override
  void initState() {
    super.initState();
    _tab = TabController(length: 2, vsync: this);
    _load();
  }

  @override
  void dispose() {
    _tab.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    if (mounted) setState(() => _loading = true);
    try {
      final media = await ApiService().getRoomMedia(widget.room.id);
      if (mounted) {
        setState(() {
          _images = media.where((m) => m.type == 'image').toList();
          _files = media.where((m) => m.type == 'file').toList();
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  static http.Client _buildClient() {
    final ctx = SecurityContext.defaultContext;
    final inner = HttpClient(context: ctx)..badCertificateCallback = (_, __, ___) => true;
    return IOClient(inner);
  }

  Future<void> _openFile(Message msg) async {
    try {
      final url = '$_serverBase${msg.fileUrl}';
      final client = _buildClient();
      final res = await client.get(Uri.parse(url));
      client.close();
      final dir = await getTemporaryDirectory();
      final name = msg.fileName ?? msg.fileUrl!.split('/').last;
      final path = '${dir.path}/$name';
      await File(path).writeAsBytes(res.bodyBytes);
      await OpenFilex.open(path);
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Megnyitás sikertelen.')));
    }
  }

  void _openImageFull(Message msg) {
    Navigator.push(context, MaterialPageRoute(
      builder: (_) => _FullScreenImage(url: '$_serverBase${msg.fileUrl}', name: msg.userName),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.room.name.isNotEmpty ? widget.room.name : 'Galéria'),
        bottom: TabBar(
          controller: _tab,
          tabs: [
            Tab(text: 'Képek${_images.isNotEmpty ? ' (${_images.length})' : ''}'),
            Tab(text: 'Fájlok${_files.isNotEmpty ? ' (${_files.length})' : ''}'),
          ],
        ),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : TabBarView(
              controller: _tab,
              children: [_buildImages(), _buildFiles()],
            ),
    );
  }

  Widget _buildImages() {
    if (_images.isEmpty) {
      return RefreshIndicator(
        onRefresh: _load,
        child: ListView(children: const [
          SizedBox(height: 120),
          Center(child: Text('Nincs kép ebben a szobában.', style: TextStyle(color: Colors.grey))),
        ]),
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: GridView.builder(
        padding: const EdgeInsets.all(4),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 3, crossAxisSpacing: 3, mainAxisSpacing: 3,
        ),
        itemCount: _images.length,
        itemBuilder: (_, i) {
          final msg = _images[i];
          return GestureDetector(
            onTap: () => _openImageFull(msg),
            child: CachedNetworkImage(
              imageUrl: '$_serverBase${msg.fileUrl}',
              fit: BoxFit.cover,
              placeholder: (_, __) => Container(color: Colors.grey.shade200),
              errorWidget: (_, __, ___) => Container(
                color: Colors.grey.shade200,
                child: const Icon(Icons.broken_image, color: Colors.grey),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildFiles() {
    if (_files.isEmpty) {
      return RefreshIndicator(
        onRefresh: _load,
        child: ListView(children: const [
          SizedBox(height: 120),
          Center(child: Text('Nincs fájl ebben a szobában.', style: TextStyle(color: Colors.grey))),
        ]),
      );
    }
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
      itemCount: _files.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (_, i) {
        final msg = _files[i];
        final name = msg.fileName ?? 'Fájl';
        final size = msg.fileSize != null ? _fmtBytes(msg.fileSize!) : '';
        return ListTile(
          leading: Container(
            width: 42, height: 42,
            decoration: BoxDecoration(color: kBlue.withOpacity(0.1), borderRadius: BorderRadius.circular(8)),
            child: Icon(_fileIcon(name), color: kBlue, size: 22),
          ),
          title: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
          subtitle: Text('${msg.userName}${size.isNotEmpty ? ' · $size' : ''}  ${_fmtDate(msg.createdAt)}',
              style: const TextStyle(fontSize: 11, color: Colors.grey)),
          trailing: const Icon(Icons.download_outlined, color: Colors.grey),
          onTap: () => _openFile(msg),
        );
      },
      ),
    );
  }

  IconData _fileIcon(String name) {
    final ext = name.contains('.') ? name.split('.').last.toLowerCase() : '';
    switch (ext) {
      case 'pdf': return Icons.picture_as_pdf;
      case 'doc': case 'docx': return Icons.description;
      case 'xls': case 'xlsx': return Icons.table_chart;
      case 'ppt': case 'pptx': return Icons.slideshow;
      case 'zip': case 'rar': case '7z': return Icons.folder_zip;
      case 'mp3': case 'wav': case 'aac': return Icons.audio_file;
      case 'mp4': case 'mov': case 'avi': return Icons.video_file;
      case 'txt': return Icons.text_snippet;
      default: return Icons.insert_drive_file_outlined;
    }
  }

  String _fmtBytes(int b) {
    if (b < 1024) return '$b B';
    if (b < 1024 * 1024) return '${(b / 1024).toStringAsFixed(1)} KB';
    return '${(b / (1024 * 1024)).toStringAsFixed(1)} MB';
  }

  String _fmtDate(String iso) {
    try {
      final dt = DateTime.parse(iso).toLocal();
      return '${dt.month}.${dt.day}.';
    } catch (_) {
      return '';
    }
  }
}

class _FullScreenImage extends StatelessWidget {
  final String url;
  final String name;
  const _FullScreenImage({required this.url, required this.name});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text(name, style: const TextStyle(color: Colors.white)),
      ),
      body: Center(
        child: InteractiveViewer(
          child: CachedNetworkImage(
            imageUrl: url,
            placeholder: (_, __) => const CircularProgressIndicator(color: Colors.white),
            errorWidget: (_, __, ___) => const Icon(Icons.broken_image, color: Colors.white, size: 48),
          ),
        ),
      ),
    );
  }
}
