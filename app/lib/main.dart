import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:firebase_core/firebase_core.dart';
import 'services/api_service.dart';
import 'services/auth_service.dart';
import 'services/push_service.dart';
import 'services/settings_service.dart';
import 'services/ws_service.dart';
import 'screens/login_screen.dart';
import 'screens/room_list_screen.dart';
import 'app_theme.dart';

class _DevHttpOverrides extends HttpOverrides {
  @override
  HttpClient createHttpClient(SecurityContext? context) {
    return super.createHttpClient(context)
      ..badCertificateCallback = (cert, host, port) => true;
  }
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  HttpOverrides.global = _DevHttpOverrides();
  if (Platform.isAndroid) await Firebase.initializeApp();
  await AuthService().init();
  await SettingsService().init();
  runApp(const TriccApp());
}

class TriccApp extends StatefulWidget {
  const TriccApp({super.key});

  @override
  State<TriccApp> createState() => _TriccAppState();
}

class _TriccAppState extends State<TriccApp> with WidgetsBindingObserver {
  StreamSubscription? _wsSub;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    PushService().init();
    if (AuthService().isLoggedIn) WsService().connect();
    SettingsService().addListener(_onSettingsChanged);
    _wsSub = WsService().events.listen(_onWsEvent);
  }

  void _onSettingsChanged() => setState(() {});

  void _onWsEvent(Map<String, dynamic> msg) {
    if (msg['type'] == 'user_updated') {
      final userId = msg['user_id'] as int?;
      if (userId == AuthService().userId) {
        AuthService().updateProfile(
          name: msg['name'] as String?,
          avatarUrl: msg['avatar_url'] as String?,
        );
        if (mounted) setState(() {});
      }
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && AuthService().isLoggedIn) {
      WsService().connect();
      PushService().setBadge(0);
      _refreshProfile();
    }
  }

  Future<void> _refreshProfile() async {
    try {
      final me = await ApiService().getMe();
      await AuthService().updateProfile(
        name: me['name'] as String?,
        avatarUrl: me['avatar_url'] as String?,
      );
      if (mounted) setState(() {});
    } catch (_) {}
  }

  @override
  void dispose() {
    _wsSub?.cancel();
    SettingsService().removeListener(_onSettingsChanged);
    WidgetsBinding.instance.removeObserver(this);
    WsService().disconnect();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'BabL42',
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(),
      darkTheme: buildDarkTheme(),
      themeMode: SettingsService().themeMode,
      builder: (context, child) => MediaQuery(
        data: MediaQuery.of(context).copyWith(
          textScaler: TextScaler.linear(SettingsService().fontScale),
        ),
        child: child!,
      ),
      home: AuthService().isLoggedIn ? const RoomListScreen() : const LoginScreen(),
    );
  }
}
