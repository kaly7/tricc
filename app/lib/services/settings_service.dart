import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

class SettingsService extends ChangeNotifier {
  static final SettingsService _i = SettingsService._();
  factory SettingsService() => _i;
  SettingsService._();

  static const _keyFontScale = 'font_scale';
  static const _keyThemeMode = 'theme_mode';
  static const _keyServerHost = 'server_host';
  static const String defaultServerHost = '194.152.151.76:9456';
  static const double minScale = 0.8;
  static const double maxScale = 1.5;
  static const double step = 0.1;

  double _fontScale = 1.0;
  double get fontScale => _fontScale;

  ThemeMode _themeMode = ThemeMode.system;
  ThemeMode get themeMode => _themeMode;

  String _serverHost = defaultServerHost;
  String get serverHost => _serverHost;

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    _fontScale = (prefs.getDouble(_keyFontScale) ?? 1.0).clamp(minScale, maxScale);
    final tm = prefs.getString(_keyThemeMode) ?? 'system';
    _themeMode = tm == 'light' ? ThemeMode.light : tm == 'dark' ? ThemeMode.dark : ThemeMode.system;
    _serverHost = prefs.getString(_keyServerHost) ?? defaultServerHost;
    notifyListeners();
  }

  Future<void> setServerHost(String host) async {
    var h = host.trim();
    if (h.isEmpty) return;
    if (!h.contains(':')) h = '$h:9456';
    if (h == _serverHost) return;
    _serverHost = h;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_keyServerHost, h);
  }

  Future<void> setThemeMode(ThemeMode mode) async {
    _themeMode = mode;
    notifyListeners();
    final prefs = await SharedPreferences.getInstance();
    final val = mode == ThemeMode.light ? 'light' : mode == ThemeMode.dark ? 'dark' : 'system';
    await prefs.setString(_keyThemeMode, val);
  }

  Future<void> increase() async {
    if (_fontScale >= maxScale) return;
    _fontScale = (_fontScale + step).clamp(minScale, maxScale);
    _fontScale = double.parse(_fontScale.toStringAsFixed(1));
    notifyListeners();
    await _save();
  }

  Future<void> decrease() async {
    if (_fontScale <= minScale) return;
    _fontScale = (_fontScale - step).clamp(minScale, maxScale);
    _fontScale = double.parse(_fontScale.toStringAsFixed(1));
    notifyListeners();
    await _save();
  }

  Future<void> reset() async {
    _fontScale = 1.0;
    notifyListeners();
    await _save();
  }

  Future<void> _save() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setDouble(_keyFontScale, _fontScale);
  }
}
