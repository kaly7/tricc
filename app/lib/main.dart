import 'package:flutter/material.dart';
import 'services/auth_service.dart';
import 'services/push_service.dart';
import 'services/ws_service.dart';
import 'screens/login_screen.dart';
import 'screens/room_list_screen.dart';
import 'app_theme.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await AuthService().init();
  runApp(const TriccApp());
}

class TriccApp extends StatefulWidget {
  const TriccApp({super.key});

  @override
  State<TriccApp> createState() => _TriccAppState();
}

class _TriccAppState extends State<TriccApp> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    PushService().init();
    if (AuthService().isLoggedIn) WsService().connect();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && AuthService().isLoggedIn) {
      WsService().connect();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    WsService().disconnect();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Tricc',
      debugShowCheckedModeBanner: false,
      theme: buildAppTheme(),
      home: AuthService().isLoggedIn ? const RoomListScreen() : const LoginScreen(),
    );
  }

}
