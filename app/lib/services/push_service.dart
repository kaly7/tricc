import 'dart:io';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_service.dart';

class PushService {
  static final PushService _i = PushService._();
  factory PushService() => _i;
  PushService._();

  static const _channel = MethodChannel('push_channel');
  static const _prefKey = 'apns_device_token';

  void Function(Map<String, dynamic>)? onNotificationTap;

  void init() {
    if (!Platform.isIOS) return;
    _channel.setMethodCallHandler((call) async {
      switch (call.method) {
        case 'onToken':
          final token = call.arguments as String;
          await _saveAndRegisterToken(token);
        case 'onMessage':
          break;
        case 'onNotificationTap':
          final data = Map<String, dynamic>.from(call.arguments as Map);
          onNotificationTap?.call(data);
      }
    });
  }

  // Minden bejelentkezés után hívd meg — újraküldi a mentett tokent a szervernek
  Future<void> reregisterIfNeeded() async {
    if (!Platform.isIOS) return;
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_prefKey);
    if (token != null) {
      try {
        await ApiService().registerPushToken(token);
      } catch (_) {}
    }
  }

  Future<void> _saveAndRegisterToken(String token) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_prefKey, token);
    try {
      await ApiService().registerPushToken(token);
    } catch (_) {}
  }
}
