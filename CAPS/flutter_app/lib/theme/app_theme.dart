import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Brand palette + typography lifted straight from the SOE resident portal
/// (request_access.php / resident_login.php) so the app matches the web 1:1.
class AppColors {
  /// Brand blue by default. These two are NOT const: at startup the app pulls
  /// the admin-configured accent (settings.php) and calls [applyAccent], so the
  /// pre-login screens follow the admin's chosen colour.
  static Color primary = const Color(0xFF1D63DA);
  static Color primaryDark = const Color(0xFF1451BE);

  /// Headings/titles — also follow the accent (deeper shades of it).
  static Color heading = const Color(0xFF102D76);
  static Color heading2 = const Color(0xFF16408D);

  /// Deepest wave layer — also follows the accent.
  static Color navy = const Color(0xFF0C3E90);

  /// Override the accent from an admin-configured hex like "#1D63DA".
  static void applyAccent(String hex) {
    final c = _parseHex(hex);
    if (c == null) return;
    primary = c;
    primaryDark = _darken(c, 0.12);
    heading = _darken(c, 0.28); // deep shade for big titles
    heading2 = _darken(c, 0.18);
    navy = _darken(c, 0.24); // deepest wave layer
  }

  static Color? _parseHex(String hex) {
    var h = hex.replaceAll('#', '').trim();
    if (h.length == 3) {
      h = h.split('').map((c) => '$c$c').join();
    }
    if (h.length != 6) return null;
    final v = int.tryParse(h, radix: 16);
    if (v == null) return null;
    return Color(0xFF000000 | v);
  }

  static Color _darken(Color c, double amount) {
    final hsl = HSLColor.fromColor(c);
    return hsl.withLightness((hsl.lightness - amount).clamp(0.0, 1.0)).toColor();
  }

  static const accent = Color(0xFF3B82F6);

  static const bgTop = Color(0xFFEEF3FB);
  static const bgBottom = Color(0xFFDCE8FA);

  static const slate800 = Color(0xFF1E293B);
  static const slate500 = Color(0xFF64748B);
  static const slate400 = Color(0xFF94A3B8);
  static const slate200 = Color(0xFFCBD5E1);

  static const danger = Color(0xFFEF4444);
  static const success = Color(0xFF16A34A);
}

class AppTheme {
  static ThemeData get light {
    final base = ThemeData(
      useMaterial3: true,
      colorSchemeSeed: AppColors.primary,
      scaffoldBackgroundColor: AppColors.bgTop,
    );
    return base.copyWith(
      textTheme: GoogleFonts.poppinsTextTheme(base.textTheme),
    );
  }

  /// Shared field decoration matching the SOE `.field-input` look.
  static InputDecoration field(
    String hint, {
    IconData? icon,
    Widget? suffix,
  }) {
    OutlineInputBorder border(Color c, [double w = 1.5]) => OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: BorderSide(color: c, width: w),
        );
    return InputDecoration(
      hintText: hint,
      hintStyle: const TextStyle(
          color: Color(0xFFA8B5CC), fontWeight: FontWeight.w400),
      filled: true,
      fillColor: Colors.white,
      prefixIcon: icon == null
          ? null
          : Icon(icon, size: 18, color: AppColors.slate400),
      suffixIcon: suffix,
      isDense: true,
      contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      enabledBorder: border(AppColors.slate200),
      focusedBorder: border(AppColors.primary),
      errorBorder: border(AppColors.danger),
      focusedErrorBorder: border(AppColors.danger),
    );
  }

  /// The gradient "btn-primary" used across the portal.
  static BoxDecoration get primaryButton => BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.primary, AppColors.primaryDark],
        ),
        borderRadius: BorderRadius.circular(14),
        boxShadow: [
          BoxShadow(
            color: AppColors.primary.withOpacity(0.30),
            blurRadius: 20,
            offset: const Offset(0, 6),
          ),
        ],
      );
}
