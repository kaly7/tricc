import 'dart:io';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'api_service.dart';

class PushService {
  static final PushService _i = PushService._();
  factory PushService() => _i;
  PushService._();

  static const _channel = MethodChannel('push_channel');
  static const _prefKey = 'apns_device_token';

  void Function(Map<String, dynamic>)? onNotificationTap;

  Future<void> init() async {
    if (Platform.isIOS) {
      await _initIos();
    } else if (Platform.isAndroid) {
      await _initAndroid();
    }
  }

  // ── iOS (APNs, meglévő logika) ──────────────────────────────────────────
  Future<void> _initIos() async {
    _channel.setMethodCallHandler((call) async {
      switch (call.method) {
        case 'onToken':
          await _saveAndRegister(call.arguments as String, platform: 'ios');
        case 'onNotificationTap':
          final data = Map<String, dynamic>.from(call.arguments as Map);
          onNotificationTap?.call(data);
      }
    });
    try { await _channel.invokeMethod('refreshToken'); } catch (_) {}
  }

  // ── Android (FCM) ────────────────────────────────────────────────────────
  Future<void> _initAndroid() async {
    final messaging = FirebaseMessaging.instance;

    await messaging.requestPermission(alert: true, badge: true, sound: true);

    final token = await messaging.getToken();
    if (token != null) await _saveAndRegister(token, platform: 'android');

    messaging.onTokenRefresh.listen((t) => _saveAndRegister(t, platform: 'android'));

    FirebaseMessaging.onMessageOpenedApp.listen((msg) {
      final data = msg.data;
      if (data.isNotEmpty) onNotificationTap?.call(data);
    });

    // Előtérben érkező értesítések megjelenítése
    await messaging.setForegroundNotificationPresentationOptions(
      alert: true, badge: true, sound: true,
    );
  }

  Future<void> reregisterIfNeeded() async {
    if (Platform.isIOS) {
      final prefs = await SharedPreferences.getInstance();
      final token = prefs.getString(_prefKey);
      if (token != null) {
        try { await ApiService().registerPushToken(token, platform: 'ios'); } catch (_) {}
      }
    } else if (Platform.isAndroid) {
      final token = await FirebaseMessaging.instance.getToken();
      if (token != null) {
        try { await ApiService().registerPushToken(token, platform: 'android'); } catch (_) {}
      }
    }
  }

  Future<void> setBadge(int count) async {
    if (!Platform.isIOS) return;
    try { await _channel.invokeMethod('setBadge', count); } catch (_) {}
  }

  Future<void> unregister() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_prefKey);
    if (token == null) return;
    try { await ApiService().unregisterPushToken(token); } catch (_) {}
    await prefs.remove(_prefKey);
    if (Platform.isAndroid) {
      try { await FirebaseMessaging.instance.deleteToken(); } catch (_) {}
    }
  }

  Future<void> _saveAndRegister(String token, {required String platform}) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_prefKey, token);
    try { await ApiService().registerPushToken(token, platform: platform); } catch (_) {}
  }
}
