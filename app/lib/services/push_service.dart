import 'dart:io';
import 'package:flutter/services.dart';
import 'api_service.dart';

class PushService {
  static final PushService _i = PushService._();
  factory PushService() => _i;
  PushService._();

  static const _channel = MethodChannel('push_channel');

  void Function(Map<String, dynamic>)? onNotificationTap;

  void init() {
    if (!Platform.isIOS) return;
    _channel.setMethodCallHandler((call) async {
      switch (call.method) {
        case 'onToken':
          final token = call.arguments as String;
          _registerToken(token);
        case 'onMessage':
          // előtérben érkező push — WS általában kezeli, de fallback
          break;
        case 'onNotificationTap':
          final data = Map<String, dynamic>.from(call.arguments as Map);
          onNotificationTap?.call(data);
      }
    });
  }

  Future<void> _registerToken(String token) async {
    try {
      await ApiService().registerPushToken(token);
    } catch (_) {}
  }
}
