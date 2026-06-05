import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

class SettingsService extends ChangeNotifier {
  static final SettingsService _i = SettingsService._();
  factory SettingsService() => _i;
  SettingsService._();

  static const _keyFontScale = 'font_scale';
  static const double minScale = 0.8;
  static const double maxScale = 1.5;
  static const double step = 0.1;

  double _fontScale = 1.0;
  double get fontScale => _fontScale;

  Future<void> init() async {
    final prefs = await SharedPreferences.getInstance();
    _fontScale = (prefs.getDouble(_keyFontScale) ?? 1.0).clamp(minScale, maxScale);
    notifyListeners();
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
