import 'package:flutter/material.dart';

/// Flat navy and gold. No shadows, no gradients, large readable text.
const navy = Color(0xFF14213D);
const gold = Color(0xFFC9A227);

ThemeData buildTheme() {
  final scheme = ColorScheme.fromSeed(seedColor: navy, primary: navy, secondary: gold, surface: Colors.white);
  final base = ThemeData(useMaterial3: true, colorScheme: scheme);
  final border = OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: const BorderSide(color: Color(0xFFB8BFCC)));
  return base.copyWith(
    scaffoldBackgroundColor: const Color(0xFFF6F7F9),
    textTheme: Typography.englishLike2021.apply(fontSizeFactor: 1.1).merge(Typography.blackMountainView).apply(bodyColor: const Color(0xFF1B1F27), displayColor: navy),
    appBarTheme: const AppBarTheme(backgroundColor: navy, foregroundColor: Colors.white, elevation: 0, scrolledUnderElevation: 0, centerTitle: false),
    cardTheme: CardThemeData(
      color: Colors.white,
      elevation: 0,
      margin: const EdgeInsets.symmetric(vertical: 6),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10), side: const BorderSide(color: Color(0xFFE1E4EA))),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(backgroundColor: navy, foregroundColor: Colors.white, minimumSize: const Size(64, 52), textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600), elevation: 0),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(foregroundColor: navy, minimumSize: const Size(64, 52), side: const BorderSide(color: navy), textStyle: const TextStyle(fontSize: 17)),
    ),
    inputDecorationTheme: InputDecorationTheme(filled: true, fillColor: Colors.white, border: border, enabledBorder: border, labelStyle: const TextStyle(fontSize: 16)),
    navigationBarTheme: const NavigationBarThemeData(backgroundColor: Colors.white, indicatorColor: Color(0x33C9A227), elevation: 0),
    snackBarTheme: const SnackBarThemeData(backgroundColor: navy, behavior: SnackBarBehavior.floating, elevation: 0),
    dividerColor: const Color(0xFFE1E4EA),
  );
}
