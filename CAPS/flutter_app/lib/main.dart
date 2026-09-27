import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'l10n/app_text.dart';
import 'screens/auth_gate.dart';
import 'services/api_service.dart';
import 'settings/app_settings.dart';
import 'theme/app_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Admin-configured portal look (settings.php) = the default when the
  // resident hasn't picked their own. Fails safe to brand blue / light.
  final api = ApiService();
  final portal = await api.fetchPublicTheme();
  api.dispose();

  // The resident's own saved preferences (language, theme, accent, text size).
  await AppSettings.instance
      .load(portalAccent: portal.accent, portalMode: portal.mode);

  runApp(const ResidentPortalApp());
}

class ResidentPortalApp extends StatefulWidget {
  const ResidentPortalApp({super.key, this.home});

  /// First screen; defaults to [AuthGate]. (Tests pass their own.)
  final Widget? home;

  @override
  State<ResidentPortalApp> createState() => _ResidentPortalAppState();
}

class _ResidentPortalAppState extends State<ResidentPortalApp> {
  final _settings = AppSettings.instance;

  @override
  void initState() {
    super.initState();
    _settings.addListener(_onSettingsChanged);
  }

  @override
  void dispose() {
    _settings.removeListener(_onSettingsChanged);
    super.dispose();
  }

  void _onSettingsChanged() {
    setState(() {});
    // Screens read AppColors / tr directly, so refresh every widget once the
    // new theme is in place (keeps state + the navigation stack).
    WidgetsBinding.instance.addPostFrameCallback((_) => rebuildAllWidgets());
  }

  @override
  Widget build(BuildContext context) {
    final theme = AppTheme.build(_settings.brightness);
    return MaterialApp(
      title: tr.appTitle,
      debugShowCheckedModeBanner: false,
      theme: theme,
      locale: _settings.language == AppLanguage.en
          ? const Locale('en')
          : const Locale('fil'),
      supportedLocales: const [Locale('en'), Locale('fil')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      builder: (context, child) {
        final mq = MediaQuery.of(context);
        return MediaQuery(
          // App text size on top of the phone's own accessibility setting.
          data: mq.copyWith(
              textScaler: TextScaler.linear(
                  mq.textScaler.scale(1) * _settings.textScale)),
          child: child!,
        );
      },
      home: widget.home ?? const AuthGate(),
    );
  }
}
