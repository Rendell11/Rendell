import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Brand palette + typography lifted straight from the SOE resident portal
/// (request_access.php / resident_login.php) so the app matches the web 1:1.
///
/// Every colour is a getter so it follows the resident's appearance settings
/// (Settings → Appearance): the accent colour and light/dark mode. The app
/// calls [applyAccent] / [applyBrightness] and then rebuilds every widget
/// (see `settings/app_settings.dart`), so screens just read `AppColors.x`.
class AppColors {
  static const Color brandBlue = Color(0xFF1D63DA);

  static Color _accent = brandBlue;
  static bool _dark = false;

  /// True while dark mode is active.
  static bool get isDark => _dark;

  /// Override the accent from a hex like "#1D63DA" (admin portal colour or the
  /// resident's own choice).
  static void applyAccent(String hex) {
    final c = parseHex(hex);
    if (c != null) _accent = c;
  }

  static void applyBrightness(Brightness b) => _dark = b == Brightness.dark;

  // ── Accent family ─────────────────────────────────────────────────────
  static Color get primary => _dark ? _lighten(_accent, 0.06) : _accent;
  static Color get primaryDark => _darken(_accent, 0.12);

  /// Headings/titles — deep shades of the accent (light shades in dark mode).
  static Color get heading =>
      _dark ? _lighten(_accent, 0.30) : _darken(_accent, 0.28);
  static Color get heading2 =>
      _dark ? _lighten(_accent, 0.22) : _darken(_accent, 0.18);

  /// Deepest wave layer.
  static Color get navy => _darken(_accent, 0.24);

  static const accent = Color(0xFF3B82F6);

  // ── Surfaces ──────────────────────────────────────────────────────────
  static Color get bgTop => _dark ? const Color(0xFF0B1220) : const Color(0xFFEEF3FB);
  static Color get bgBottom =>
      _dark ? const Color(0xFF1E293B) : const Color(0xFFDCE8FA);

  /// Plain page background for module screens (dashboard, chat, complaints…).
  static Color get scaffold =>
      _dark ? const Color(0xFF0B1220) : const Color(0xFFEEF2FB);

  /// Cards / sheets (was hard-coded white).
  static Color get surface => _dark ? const Color(0xFF162032) : Colors.white;

  /// Slightly recessed panel inside a card.
  static Color get surfaceAlt =>
      _dark ? const Color(0xFF0F1A2B) : const Color(0xFFF8FAFC);

  /// Input fill.
  static Color get fieldFill => _dark ? const Color(0xFF0F1A2B) : Colors.white;

  /// Soft chip / track background.
  static Color get muted => _dark ? const Color(0xFF1E293B) : const Color(0xFFF1F5F9);

  static Color get border => _dark ? const Color(0xFF2B3A52) : const Color(0xFFE2E8F0);

  /// Dark top bar used by the resident module screens.
  static Color get appBar => _dark ? const Color(0xFF020617) : const Color(0xFF0F172A);

  // ── Text ──────────────────────────────────────────────────────────────
  static Color get slate800 => _dark ? const Color(0xFFE2E8F0) : const Color(0xFF1E293B);
  static Color get slate500 => _dark ? const Color(0xFFA3B1C6) : const Color(0xFF64748B);
  static Color get slate400 => _dark ? const Color(0xFF8292A9) : const Color(0xFF94A3B8);
  static Color get slate200 => _dark ? const Color(0xFF334155) : const Color(0xFFCBD5E1);

  // ── Status tints (info / success / warning / danger boxes) ────────────
  static Color get infoBg => _dark ? const Color(0xFF14264A) : const Color(0xFFEFF6FF);
  static Color get infoBorder => _dark ? const Color(0xFF1E3A8A) : const Color(0xFFBFDBFE);
  static Color get infoText => _dark ? const Color(0xFF93C5FD) : const Color(0xFF1E40AF);
  static Color get infoText2 => _dark ? const Color(0xFFBFDBFE) : const Color(0xFF2563EB);

  static Color get successBg => _dark ? const Color(0xFF0E2A1F) : const Color(0xFFECFDF5);
  static Color get successBorder =>
      _dark ? const Color(0xFF166534) : const Color(0xFFA7F3D0);
  static Color get successText =>
      _dark ? const Color(0xFF86EFAC) : const Color(0xFF15803D);

  static Color get warnBg => _dark ? const Color(0xFF2E2410) : const Color(0xFFFFFBEB);
  static Color get warnBorder => _dark ? const Color(0xFF92400E) : const Color(0xFFFDE68A);
  static Color get warnText => _dark ? const Color(0xFFFCD34D) : const Color(0xFF92400E);

  static Color get dangerBg => _dark ? const Color(0xFF2F1515) : const Color(0xFFFEF2F2);
  static Color get dangerBorder =>
      _dark ? const Color(0xFF7F1D1D) : const Color(0xFFFCA5A5);
  static Color get dangerText => _dark ? const Color(0xFFFCA5A5) : const Color(0xFF991B1B);

  static const danger = Color(0xFFEF4444);
  static const success = Color(0xFF16A34A);

  // ── helpers ───────────────────────────────────────────────────────────
  static Color? parseHex(String hex) {
    var h = hex.replaceAll('#', '').trim();
    if (h.length == 3) {
      h = h.split('').map((c) => '$c$c').join();
    }
    if (h.length != 6) return null;
    final v = int.tryParse(h, radix: 16);
    if (v == null) return null;
    return Color(0xFF000000 | v);
  }

  static String toHex(Color c) =>
      '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0').toUpperCase()}';

  static Color _darken(Color c, double amount) {
    final hsl = HSLColor.fromColor(c);
    return hsl.withLightness((hsl.lightness - amount).clamp(0.0, 1.0)).toColor();
  }

  static Color _lighten(Color c, double amount) {
    final hsl = HSLColor.fromColor(c);
    return hsl.withLightness((hsl.lightness + amount).clamp(0.0, 1.0)).toColor();
  }
}

class AppTheme {
  /// Material theme for the current [AppColors] state.
  static ThemeData build(Brightness brightness) {
    final base = ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorSchemeSeed: AppColors.primary,
      scaffoldBackgroundColor: AppColors.bgTop,
    );
    return base.copyWith(
      textTheme: GoogleFonts.poppinsTextTheme(base.textTheme),
      cardColor: AppColors.surface,
      dividerColor: AppColors.border,
      snackBarTheme: const SnackBarThemeData(behavior: SnackBarBehavior.floating),
    );
  }

  static ThemeData get light => build(Brightness.light);

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
      hintStyle: TextStyle(
          color: AppColors.isDark ? const Color(0xFF64748B) : const Color(0xFFA8B5CC),
          fontWeight: FontWeight.w400),
      filled: true,
      fillColor: AppColors.fieldFill,
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
            color: AppColors.primary.withValues(alpha: 0.30),
            blurRadius: 20,
            offset: const Offset(0, 6),
          ),
        ],
      );
}
