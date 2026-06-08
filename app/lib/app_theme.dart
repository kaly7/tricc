import 'package:flutter/material.dart';

const kBlue = Color(0xFF1E5BB5);
const kBlueDark = Color(0xFF153D80);
const kLime = Color(0xFF7CC042);
const kLimeDark = Color(0xFF5E9A2E);

ThemeData buildAppTheme() {
  return ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(seedColor: kLime, primary: kLime, secondary: kBlue),
    appBarTheme: const AppBarTheme(
      backgroundColor: kBlue,
      foregroundColor: Colors.white,
      elevation: 0,
      centerTitle: false,
    ),
    bottomNavigationBarTheme: const BottomNavigationBarThemeData(
      selectedItemColor: kLime,
      unselectedItemColor: Colors.grey,
      backgroundColor: Colors.white,
      elevation: 8,
    ),
    floatingActionButtonTheme: const FloatingActionButtonThemeData(
      backgroundColor: kLime,
      foregroundColor: Colors.white,
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: kLime,
        foregroundColor: Colors.white,
        minimumSize: const Size(double.infinity, 50),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: kBlue, width: 2),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    ),
  );
}

ThemeData buildDarkTheme() {
  return ThemeData(
    useMaterial3: true,
    brightness: Brightness.dark,
    colorScheme: ColorScheme.fromSeed(seedColor: kLime, primary: kLime, secondary: kBlue, brightness: Brightness.dark),
    appBarTheme: const AppBarTheme(
      backgroundColor: kBlueDark,
      foregroundColor: Colors.white,
      elevation: 0,
      centerTitle: false,
    ),
    bottomNavigationBarTheme: const BottomNavigationBarThemeData(
      selectedItemColor: kLime,
      unselectedItemColor: Colors.grey,
      elevation: 8,
    ),
    floatingActionButtonTheme: const FloatingActionButtonThemeData(
      backgroundColor: kLime,
      foregroundColor: Colors.white,
    ),
    elevatedButtonTheme: ElevatedButtonThemeData(
      style: ElevatedButton.styleFrom(
        backgroundColor: kLime,
        foregroundColor: Colors.white,
        minimumSize: const Size(double.infinity, 50),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: kBlue, width: 2),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    ),
  );
}

const kAppVersion = '1.1.0';
const kAppBuild = '50';
const kAppReleaseDate = '2026. június 8.';

void showAboutDialog(BuildContext context) {
  showDialog<void>(
    context: context,
    builder: (_) => Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 36, vertical: 36),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Image.asset('assets/logo.png', width: 96),
            const SizedBox(height: 20),
            RichText(
              text: const TextSpan(children: [
                TextSpan(text: 'BabL', style: TextStyle(fontSize: 30, fontWeight: FontWeight.bold, color: kBlue)),
                TextSpan(text: '42',   style: TextStyle(fontSize: 30, fontWeight: FontWeight.bold, color: kLime)),
              ]),
            ),
            const SizedBox(height: 10),
            Text('v$kAppVersion ($kAppBuild)', style: const TextStyle(fontSize: 15, color: Colors.grey)),
            const SizedBox(height: 2),
            Text(kAppReleaseDate, style: const TextStyle(fontSize: 13, color: Colors.grey)),
            const SizedBox(height: 20),
            const Divider(),
            const SizedBox(height: 12),
            const Text('© RV42 / kaly', style: TextStyle(fontSize: 13, color: Colors.grey)),
          ],
        ),
      ),
    ),
  );
}

class AppBarLogo extends StatelessWidget {
  const AppBarLogo({super.key});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => showAboutDialog(context),
      child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Image.asset('assets/logo.png', height: 46),
        const SizedBox(width: 8),
        RichText(
          text: const TextSpan(
            children: [
              TextSpan(
                text: 'BabL',
                style: TextStyle(
                  color: Colors.white,
                  fontSize: 22,
                  fontWeight: FontWeight.bold,
                  letterSpacing: 0.5,
                ),
              ),
              TextSpan(
                text: '42',
                style: TextStyle(
                  color: kLime,
                  fontSize: 22,
                  fontWeight: FontWeight.bold,
                  letterSpacing: 0.5,
                ),
              ),
            ],
          ),
        ),
      ],
      ),
    );
  }
}
