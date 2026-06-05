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

  // Hívd meg az app indulása után (main.dart) — beállítja a handlert
  // és lekéri a tokent ha az már korábban megérkezett
  Future<void> init() async {
    if (!Platform.isIOS) return;

    _channel.setMethodCallHandler((call) async {
      switch (call.method) {
        case 'onToken':
          final token = call.arguments as String;
          await _saveAndRegister(token);
        case 'onMessage':
          break;
        case 'onNotificationTap':
          final data = Map<String, dynamic>.from(call.arguments as Map);
          onNotificationTap?.call(data);
      }
    });

    // Lekéri az AppDelegate-ben tárolt tokent (ha már megérkezett iOS-től)
    try {
      final stored = await _channel.invokeMethod<String?>('getStoredToken');
      if (stored != null && stored.isNotEmpty) {
        await _saveAndRegister(stored);
        return;
      }
    } catch (_) {}

    // Ha nincs tárolt token, megkérjük iOS-t hogy küldje el újra
    try {
      await _channel.invokeMethod('refreshToken');
    } catch (_) {}
  }

  // Bejelentkezéskor hívd meg — a mentett tokent újraküldi a szervernek
  Future<void> reregisterIfNeeded() async {
    if (!Platform.isIOS) return;
    // Előbb próbáljuk a natív tároltból
    try {
      final stored = await _channel.invokeMethod<String?>('getStoredToken');
      if (stored != null && stored.isNotEmpty) {
        await _saveAndRegister(stored);
        return;
      }
    } catch (_) {}
    // Fallback: SharedPreferences-ből
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_prefKey);
    if (token != null) {
      try { await ApiService().registerPushToken(token); } catch (_) {}
    } else {
      // Nincs semmi — iOS-t kérjük meg hogy küldje el a tokent
      try { await _channel.invokeMethod('refreshToken'); } catch (_) {}
    }
  }

  Future<void> _saveAndRegister(String token) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_prefKey, token);
    try { await ApiService().registerPushToken(token); } catch (_) {}
  }
}
