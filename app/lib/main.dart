import 'dart:async';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'services/api_service.dart';
import 'services/auth_service.dart';
import 'services/call_service.dart';
import 'services/push_service.dart';
import 'services/settings_service.dart';
import 'services/ws_service.dart';
import 'screens/incoming_call_screen.dart';
import 'screens/active_call_screen.dart';
import 'screens/login_screen.dart';
import 'screens/room_list_screen.dart';
import 'app_theme.dart';

final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();

// Android background/killed állapotban érkező FCM üzenet kezelő
@pragma('vm:entry-point')
Future<void> _firebaseBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
  // A UI-t nem tudjuk kezelni itt — a rendszer megmutatja az értesítést,
  // a user tapra onMessageOpenedApp fut le az app indulásakor
}

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
  if (Platform.isAndroid) {
    await Firebase.initializeApp();
    FirebaseMessaging.onBackgroundMessage(_firebaseBackgroundHandler);
  }
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
    if (AuthService().isLoggedIn) {
      WsService().connect();
      _initCallService();
    }
    SettingsService().addListener(_onSettingsChanged);
    _wsSub = WsService().events.listen(_onWsEvent);
  }

  void _initCallService() {
    final svc = CallService();
    svc.onIncomingCall = () {
      navigatorKey.currentState?.push(
        MaterialPageRoute(builder: (_) => const IncomingCallScreen()),
      );
    };
    svc.onCallStarted = () {
      navigatorKey.currentState?.push(
        MaterialPageRoute(builder: (_) => const ActiveCallScreen()),
      );
    };
    svc.onCallEnded = () {};
    svc.onCallError = (msg) {
      final ctx = navigatorKey.currentContext;
      if (ctx != null) {
        ScaffoldMessenger.of(ctx).showSnackBar(
          SnackBar(content: Text(msg), duration: const Duration(seconds: 3)),
        );
      }
    };
    svc.init();

    // Push értesítésből érkező hívás kezelése
    PushService().onNotificationTap = _handleCallPush;

    // Killed állapotból push tapra induló app
    if (Platform.isAndroid) {
      FirebaseMessaging.instance.getInitialMessage().then((msg) {
        if (msg != null) _handleCallPush(msg.data);
      });
    }
  }

  void _handleCallPush(Map<String, dynamic> data) {
    if (data['type'] != 'incoming_call') return;
    final callId = data['call_id'] as String?;
    final callerId = int.tryParse(data['caller_id']?.toString() ?? '');
    final callerName = data['caller_name'] as String? ?? 'Ismeretlen';
    if (callId == null || callerId == null) return;
    if (!AuthService().isLoggedIn) return;
    if (!WsService().isConnected) WsService().connect();
    CallService().handleIncomingCallPush(
      callId: callId,
      callerId: callerId,
      callerName: callerName,
    );
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
      navigatorKey: navigatorKey,
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
