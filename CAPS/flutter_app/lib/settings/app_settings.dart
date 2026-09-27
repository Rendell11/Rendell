import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../config/api_config.dart';

import '../l10n/app_text.dart';
import '../l10n/app_text_en.dart';
import '../l10n/app_text_fil.dart';
import '../theme/app_theme.dart';
import 'settings_api.dart';

enum AppLanguage { en, fil }

/// How big the app's text is (Settings → Appearance → Text size).
enum AppTextSize { small, normal, large }

/// Accent presets the resident can pick in Settings (plus "Barangay default").
class AccentOption {
  final String hex;
  const AccentOption(this.hex);
  Color get color => AppColors.parseHex(hex)!;
}

const List<AccentOption> kAccentOptions = [
  AccentOption('#1D63DA'), // blue (brand)
  AccentOption('#4F46E5'), // indigo
  AccentOption('#7C3AED'), // violet
  AccentOption('#DB2777'), // pink
  AccentOption('#E11D48'), // rose
  AccentOption('#EA580C'), // orange
  AccentOption('#D97706'), // amber
  AccentOption('#059669'), // emerald
  AccentOption('#0D9488'), // teal
  AccentOption('#0891B2'), // cyan
  AccentOption('#475569'), // slate
];

/// The resident's app preferences: language, light/dark mode, accent colour
/// and text size.
///
/// * Saved on the device (secure storage, survives logout) so they apply from
///   the very first screen, including login.
/// * Synced to the server (`user_preferences`, via `preferences.php`) once a
///   resident is signed in, so they follow the resident to another phone.
/// * Unset values fall back to what the admin configured for the portal
///   (theme.php): accent colour and light/dark mode.
class AppSettings extends ChangeNotifier with WidgetsBindingObserver {
  AppSettings._();

  static final AppSettings instance = AppSettings._();

  final _store = const FlutterSecureStorage();
  final _api = SettingsApi();

  static const _kLanguage = 'pref_language';
  static const _kThemeMode = 'pref_theme_mode';
  static const _kAccent = 'pref_accent';
  static const _kTextSize = 'pref_text_size';

  AppLanguage _language = AppLanguage.fil;
  ThemeMode? _themeMode; // null → follow the barangay portal setting
  String? _accentHex; // null → barangay default
  AppTextSize _textSize = AppTextSize.normal;

  String _portalAccent = '#1D63DA';
  ThemeMode _portalMode = ThemeMode.light;

  int? _residentId;

  AppLanguage get language => _language;
  AppText get text =>
      _language == AppLanguage.en ? const AppTextEn() : const AppTextFil();

  /// The resident's own choice (null = barangay default).
  ThemeMode? get themeModeChoice => _themeMode;
  ThemeMode get themeMode => _themeMode ?? _portalMode;

  /// The resident's own accent (null = barangay default).
  String? get accentChoice => _accentHex;
  String get accentHex => _accentHex ?? _portalAccent;
  String get portalAccent => _portalAccent;

  AppTextSize get textSize => _textSize;
  double get textScale => switch (_textSize) {
        AppTextSize.small => 0.9,
        AppTextSize.normal => 1.0,
        AppTextSize.large => 1.15,
      };

  Brightness get brightness => switch (themeMode) {
        ThemeMode.dark => Brightness.dark,
        ThemeMode.light => Brightness.light,
        ThemeMode.system =>
          WidgetsBinding.instance.platformDispatcher.platformBrightness,
      };

  /// Language code sent to the backend so its messages match the app.
  String get apiLang => _language == AppLanguage.en ? 'en' : 'fil';

  // ── Startup ────────────────────────────────────────────────────────────

  /// Load the saved preferences and the admin's portal defaults.
  Future<void> load({String? portalAccent, String? portalMode}) async {
    if (portalAccent != null) _portalAccent = portalAccent;
    if (portalMode != null) {
      _portalMode = portalMode == 'dark' ? ThemeMode.dark : ThemeMode.light;
    }
    try {
      _language =
          _parseLanguage(await _store.read(key: _kLanguage)) ?? _language;
      _themeMode = _parseThemeMode(await _store.read(key: _kThemeMode));
      _accentHex = _parseAccent(await _store.read(key: _kAccent));
      _textSize =
          _parseTextSize(await _store.read(key: _kTextSize)) ?? _textSize;
    } catch (_) {
      // secure storage unavailable (e.g. some web setups) — keep defaults
    }
    WidgetsBinding.instance.addObserver(this);
    _apply();
  }

  @override
  void didChangePlatformBrightness() {
    if (themeMode == ThemeMode.system) {
      _apply();
      notifyListeners();
    }
  }

  // ── Resident binding / server sync ────────────────────────────────────

  /// Called when a resident is signed in: pull their saved preferences from
  /// the server (they win over the device copy) and push future changes.
  Future<void> bindResident(int residentId) async {
    _residentId = residentId;
    final remote = await _api.fetch(residentId);
    if (remote == null || remote.isEmpty) {
      // First time on the server: upload what this device has.
      _push();
      return;
    }
    var changed = false;
    final lang = _parseLanguage(remote['app_language']);
    if (lang != null && lang != _language) {
      _language = lang;
      changed = true;
    }
    if (remote.containsKey('app_theme_mode')) {
      final m = _parseThemeMode(remote['app_theme_mode']);
      if (m != _themeMode) {
        _themeMode = m;
        changed = true;
      }
    }
    if (remote.containsKey('app_accent')) {
      final a = _parseAccent(remote['app_accent']);
      if (a != _accentHex) {
        _accentHex = a;
        changed = true;
      }
    }
    final size = _parseTextSize(remote['app_text_size']);
    if (size != null && size != _textSize) {
      _textSize = size;
      changed = true;
    }
    if (changed) {
      await _saveLocal();
      _apply();
      notifyListeners();
    }
  }

  /// Stop syncing (logout). Device preferences are kept.
  void unbindResident() => _residentId = null;

  // ── Setters used by the Settings screen ───────────────────────────────

  Future<void> setLanguage(AppLanguage v) => _update(() => _language = v);
  Future<void> setThemeMode(ThemeMode? v) => _update(() => _themeMode = v);
  Future<void> setAccent(String? hex) =>
      _update(() => _accentHex = _parseAccent(hex));
  Future<void> setTextSize(AppTextSize v) => _update(() => _textSize = v);

  /// Back to the barangay defaults (keeps the language).
  Future<void> resetAppearance() => _update(() {
        _themeMode = null;
        _accentHex = null;
        _textSize = AppTextSize.normal;
      });

  Future<void> _update(void Function() change) async {
    change();
    _apply();
    notifyListeners();
    await _saveLocal();
    _push();
  }

  void _apply() {
    ApiConfig.lang = apiLang;
    AppColors.applyAccent(accentHex);
    AppColors.applyBrightness(brightness);
  }

  Future<void> _saveLocal() async {
    try {
      await _store.write(key: _kLanguage, value: _language.name);
      await _store.write(
          key: _kThemeMode, value: _themeMode?.name ?? 'default');
      await _store.write(key: _kAccent, value: _accentHex ?? 'default');
      await _store.write(key: _kTextSize, value: _textSize.name);
    } catch (_) {}
  }

  void _push() {
    final rid = _residentId;
    if (rid == null) return;
    _api.save(rid, {
      'app_language': _language.name,
      'app_theme_mode': _themeMode?.name ?? 'default',
      'app_accent': _accentHex ?? 'default',
      'app_text_size': _textSize.name,
    });
  }

  // ── parsing ───────────────────────────────────────────────────────────

  static AppLanguage? _parseLanguage(String? v) => switch (v) {
        'en' => AppLanguage.en,
        'fil' => AppLanguage.fil,
        _ => null,
      };

  static ThemeMode? _parseThemeMode(String? v) => switch (v) {
        'light' => ThemeMode.light,
        'dark' => ThemeMode.dark,
        'system' => ThemeMode.system,
        _ => null,
      };

  static String? _parseAccent(String? v) {
    if (v == null || v == 'default') return null;
    final c = AppColors.parseHex(v);
    return c == null ? null : AppColors.toHex(c);
  }

  static AppTextSize? _parseTextSize(String? v) => switch (v) {
        'small' => AppTextSize.small,
        'normal' => AppTextSize.normal,
        'large' => AppTextSize.large,
        _ => null,
      };
}

/// Marks every element dirty so widgets that read [AppColors] / [tr] directly
/// (including `const` ones) pick up a settings change without losing state or
/// the navigation stack.
void rebuildAllWidgets() {
  void mark(Element e) {
    e.markNeedsBuild();
    e.visitChildren(mark);
  }

  WidgetsBinding.instance.rootElement?.visitChildren(mark);
}
