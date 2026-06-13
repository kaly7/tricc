import 'package:flutter/material.dart';
import 'package:livekit_client/livekit_client.dart' as lk;
import '../services/group_call_service.dart';
import '../app_theme.dart';

class GroupCallScreen extends StatefulWidget {
  final int roomId;
  final String roomName;
  const GroupCallScreen({super.key, required this.roomId, required this.roomName});

  @override
  State<GroupCallScreen> createState() => _GroupCallScreenState();
}

class _GroupCallScreenState extends State<GroupCallScreen> {
  final _svc = GroupCallService();

  @override
  void initState() {
    super.initState();
    _svc.addListener(_rebuild);
    _svc.join(widget.roomId, widget.roomName);
  }

  @override
  void dispose() {
    _svc.removeListener(_rebuild);
    super.dispose();
  }

  void _rebuild() {
    if (mounted) setState(() {});
  }

  Future<void> _leave() async {
    await _svc.leave();
    if (mounted) Navigator.pop(context);
  }

  void _showAudioSheet(BuildContext context) {
    final svc = GroupCallService();
    showModalBottomSheet(
      context: context,
      backgroundColor: const Color(0xFF16213E),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (_) => ListenableBuilder(
        listenable: svc,
        builder: (_, __) {
          final btDevices = svc.audioDevices
              .where((d) => d.label.toLowerCase().contains('bluetooth') ||
                  d.label.toLowerCase().contains('bt') ||
                  d.label.toLowerCase().contains('airpod') ||
                  d.label.toLowerCase().contains('wireless'))
              .toList();

          return SafeArea(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Padding(
                  padding: EdgeInsets.all(16),
                  child: Text('Hangkimenet', style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w600)),
                ),
                _AudioOption(
                  icon: Icons.phone_in_talk,
                  label: 'Fülhallgató',
                  subtitle: 'Telefon hangszórója (privát)',
                  selected: svc.audioOutput == AudioOutput.earpiece,
                  onTap: () { svc.setAudioOutput(AudioOutput.earpiece); Navigator.pop(context); },
                ),
                _AudioOption(
                  icon: Icons.volume_up,
                  label: 'Kihangosítás',
                  subtitle: 'Beépített hangszóró',
                  selected: svc.audioOutput == AudioOutput.speaker,
                  onTap: () { svc.setAudioOutput(AudioOutput.speaker); Navigator.pop(context); },
                ),
                if (btDevices.isNotEmpty)
                  ...btDevices.map((d) => _AudioOption(
                    icon: Icons.bluetooth_audio,
                    label: d.label.isNotEmpty ? d.label : 'Bluetooth',
                    subtitle: 'Bluetooth eszköz',
                    selected: svc.audioOutput == AudioOutput.bluetooth,
                    onTap: () { svc.setAudioOutput(AudioOutput.bluetooth, deviceId: d.deviceId); Navigator.pop(context); },
                  ))
                else
                  _AudioOption(
                    icon: Icons.bluetooth_audio,
                    label: 'Bluetooth',
                    subtitle: 'Nincs csatlakoztatva',
                    selected: false,
                    enabled: false,
                    onTap: () {},
                  ),
                const SizedBox(height: 8),
              ],
            ),
          );
        },
      ),
    );
    svc.reloadAudioDevices();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF1A1A2E),
      appBar: AppBar(
        backgroundColor: const Color(0xFF1A1A2E),
        foregroundColor: Colors.white,
        title: Text(widget.roomName, style: const TextStyle(color: Colors.white)),
        leading: IconButton(
          icon: const Icon(Icons.keyboard_arrow_down, color: Colors.white),
          tooltip: 'Háttérbe (hívás folytatódik)',
          onPressed: () => Navigator.pop(context),
        ),
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    final error = _svc.error;
    if (error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, color: Colors.white54, size: 48),
              const SizedBox(height: 12),
              Text(error, style: const TextStyle(color: Colors.white54), textAlign: TextAlign.center),
              const SizedBox(height: 20),
              ElevatedButton(onPressed: _leave, child: const Text('Vissza')),
            ],
          ),
        ),
      );
    }

    if (_svc.isConnecting || !_svc.isActive || _svc.room == null) {
      return const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(color: Colors.white),
            SizedBox(height: 16),
            Text('Csatlakozás...', style: TextStyle(color: Colors.white54)),
          ],
        ),
      );
    }

    return _buildCallUI(_svc.room!);
  }

  Widget _buildCallUI(lk.Room room) {
    final localName = room.localParticipant?.name ?? '';
    final remotes = room.remoteParticipants.values.toList();

    final allParticipants = <_ParticipantInfo>[
      _ParticipantInfo(
        name: localName.isNotEmpty ? '$localName (te)' : 'Te',
        isMuted: _svc.isMuted,
        isLocal: true,
      ),
      ...remotes.map((p) => _ParticipantInfo(
        name: p.name.isNotEmpty ? p.name : p.identity,
        isMuted: !p.isMicrophoneEnabled(),
        isLocal: false,
      )),
    ];

    return Column(
      children: [
        Expanded(
          child: allParticipants.length == 1
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      _ParticipantTile(info: allParticipants.first),
                      const SizedBox(height: 24),
                      const Text('Várakozás a többiekre...', style: TextStyle(color: Colors.white38, fontSize: 13)),
                    ],
                  ),
                )
              : GridView.builder(
                  padding: const EdgeInsets.all(16),
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    mainAxisSpacing: 12,
                    crossAxisSpacing: 12,
                  ),
                  itemCount: allParticipants.length,
                  itemBuilder: (_, i) => _ParticipantTile(info: allParticipants[i]),
                ),
        ),
        _BottomBar(
          isMuted: _svc.isMuted,
          audioOutput: _svc.audioOutput,
          onMute: _svc.toggleMute,
          onLeave: _leave,
          onAudioOutput: () => _showAudioSheet(context),
        ),
      ],
    );
  }
}

class _ParticipantInfo {
  final String name;
  final bool isMuted;
  final bool isLocal;
  const _ParticipantInfo({required this.name, required this.isMuted, required this.isLocal});
}

class _ParticipantTile extends StatelessWidget {
  final _ParticipantInfo info;
  const _ParticipantTile({required this.info});

  @override
  Widget build(BuildContext context) {
    final initial = info.name.isNotEmpty ? info.name[0].toUpperCase() : '?';
    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFF16213E),
        borderRadius: BorderRadius.circular(16),
        border: info.isLocal ? Border.all(color: kBlue, width: 2) : null,
      ),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Stack(
            alignment: Alignment.bottomRight,
            children: [
              CircleAvatar(
                radius: 30,
                backgroundColor: kBlue.withValues(alpha: 0.3),
                child: Text(initial, style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.bold)),
              ),
              if (info.isMuted)
                Container(
                  padding: const EdgeInsets.all(3),
                  decoration: const BoxDecoration(color: Colors.red, shape: BoxShape.circle),
                  child: const Icon(Icons.mic_off, color: Colors.white, size: 12),
                ),
            ],
          ),
          const SizedBox(height: 10),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            child: Text(
              info.name,
              style: const TextStyle(color: Colors.white, fontSize: 13),
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}

class _BottomBar extends StatelessWidget {
  final bool isMuted;
  final AudioOutput audioOutput;
  final VoidCallback onMute;
  final VoidCallback onLeave;
  final VoidCallback onAudioOutput;
  const _BottomBar({
    required this.isMuted,
    required this.audioOutput,
    required this.onMute,
    required this.onLeave,
    required this.onAudioOutput,
  });

  @override
  Widget build(BuildContext context) {
    final audioIcon = switch (audioOutput) {
      AudioOutput.earpiece  => Icons.phone_in_talk,
      AudioOutput.speaker   => Icons.volume_up,
      AudioOutput.bluetooth => Icons.bluetooth_audio,
    };

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceEvenly,
          children: [
            _CallButton(
              icon: isMuted ? Icons.mic_off : Icons.mic,
              label: isMuted ? 'Némítva' : 'Mikrofon',
              color: isMuted ? Colors.red : Colors.white24,
              onTap: onMute,
            ),
            _CallButton(
              icon: audioIcon,
              label: switch (audioOutput) {
                AudioOutput.earpiece  => 'Fülhallgató',
                AudioOutput.speaker   => 'Kihangosítás',
                AudioOutput.bluetooth => 'Bluetooth',
              },
              color: audioOutput == AudioOutput.speaker ? kBlue : Colors.white24,
              onTap: onAudioOutput,
            ),
            _CallButton(
              icon: Icons.call_end,
              label: 'Kilépés',
              color: Colors.red,
              onTap: onLeave,
            ),
          ],
        ),
      ),
    );
  }
}

class _CallButton extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;
  const _CallButton({required this.icon, required this.label, required this.color, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 64, height: 64,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
            child: Icon(icon, color: Colors.white, size: 28),
          ),
          const SizedBox(height: 8),
          Text(label, style: const TextStyle(color: Colors.white70, fontSize: 12)),
        ],
      ),
    );
  }
}

class _AudioOption extends StatelessWidget {
  final IconData icon;
  final String label;
  final String subtitle;
  final bool selected;
  final bool enabled;
  final VoidCallback onTap;
  const _AudioOption({
    required this.icon,
    required this.label,
    required this.subtitle,
    required this.selected,
    required this.onTap,
    this.enabled = true,
  });

  @override
  Widget build(BuildContext context) {
    final color = enabled ? (selected ? kBlue : Colors.white70) : Colors.white24;
    return ListTile(
      onTap: enabled ? onTap : null,
      leading: Icon(icon, color: color, size: 26),
      title: Text(label, style: TextStyle(color: color, fontWeight: selected ? FontWeight.w600 : FontWeight.normal)),
      subtitle: Text(subtitle, style: const TextStyle(color: Colors.white38, fontSize: 12)),
      trailing: selected ? Icon(Icons.check_circle, color: kBlue, size: 20) : null,
    );
  }
}
