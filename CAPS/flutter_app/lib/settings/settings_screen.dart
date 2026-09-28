import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../profile/profile_api.dart';
import '../profile/profile_avatar.dart';
import '../profile/profile_screen.dart';
import '../screens/login_screen.dart';
import '../services/biometric_service.dart';
import '../services/session_service.dart';
import '../theme/app_theme.dart';
import '../disaster/alert_sound.dart';
import 'app_settings.dart';
import 'change_password_screen.dart';
import 'change_pin_screen.dart';
import 'settings_api.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// SETTINGS — the resident's own app settings.
///   • Profile summary
///   • Appearance: light / dark / system, accent colour, text size
///   • Language: English / Filipino (whole app + backend messages)
///   • Security: change password, change PIN, biometric unlock, session
///   • About + reset, logout
///
/// Preferences are applied live (see AppSettings) and synced to the server.
/// Kept in its own `settings/` folder for isolated debugging.
/// ─────────────────────────────────────────────────────────────────────────
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  final _settings = AppSettings.instance;
  final _session = SessionService();
  final _biometric = BiometricService();

  bool _hasPin = false;
  bool _biometricAvailable = false;
  bool _biometricOn = false;
  DateTime? _sessionUntil;

  static const appVersion = '1.0.0';

  @override
  void initState() {
    super.initState();
    _settings.addListener(_onSettings);
    _loadSecurity();
    _loadPhoto();
  }

  String? _photoUrl;

  Future<void> _loadPhoto() async {
    final api = ProfileApi();
    final res = await api.get(widget.resident.residentId);
    api.dispose();
    if (mounted && res.ok) setState(() => _photoUrl = res.data?.photoUrl);
  }

  Future<void> _openProfile() async {
    final changed = await Navigator.of(context).push<bool>(MaterialPageRoute(
        builder: (_) => ProfileScreen(resident: widget.resident)));
    if (changed == true) _loadPhoto();
  }

  @override
  void dispose() {
    _settings.removeListener(_onSettings);
    super.dispose();
  }

  void _onSettings() {
    if (mounted) setState(() {});
  }

  Future<void> _loadSecurity() async {
    final hasPin = await _session.hasPin();
    final available = await _biometric.isAvailable();
    final on = await _session.biometricEnabled();
    final until = await _session.sessionExpiry();
    if (!mounted) return;
    setState(() {
      _hasPin = hasPin;
      _biometricAvailable = available;
      _biometricOn = on;
      _sessionUntil = until;
    });
  }

  Future<void> _toggleBiometric(bool v) async {
    if (v) {
      final ok = await _biometric.authenticate(tr.biometricConfirmReason);
      if (!ok) return;
    }
    await _session.setBiometricEnabled(v);
    if (mounted) setState(() => _biometricOn = v);
  }

  Future<void> _openChangePin() async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => ChangePinScreen(hasPin: _hasPin)),
    );
    if (changed == true && mounted) {
      _snack(_hasPin ? tr.pinChanged : tr.pinCreated);
      _loadSecurity();
    }
  }

  Future<void> _logout() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr.logoutConfirmTitle),
        content: Text(tr.logoutConfirmBody),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(tr.cancel)),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(tr.logout)),
        ],
      ),
    );
    if (ok != true) return;
    await _session.clear();
    _settings.unbindResident();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (_) => false,
    );
  }

  Future<void> _logoutAll() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr.logoutAllDevices),
        content: Text(tr.logoutAllDevicesBody),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(tr.cancel)),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(tr.logout)),
        ],
      ),
    );
    if (ok != true) return;
    final api = SettingsApi();
    final res = await api.logoutAllDevices();
    api.dispose();
    if (!mounted) return;
    if (!res.ok) {
      _snack(res.message);
      return;
    }
    await _session.clear();
    _settings.unbindResident();
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(res.message)));
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (_) => false,
    );
  }

  Future<void> _resetAppearance() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr.resetAppearanceTitle),
        content: Text(tr.resetAppearanceBody),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(tr.cancel)),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true), child: Text(tr.reset)),
        ],
      ),
    );
    if (ok == true) {
      await _settings.resetAppearance();
      if (mounted) _snack(tr.resetAppearanceDone);
    }
  }

  void _snack(String m) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: AppBar(
        backgroundColor: AppColors.appBar,
        foregroundColor: Colors.white,
        elevation: 0,
        title: Row(
          children: [
            Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                    colors: [AppColors.primary, AppColors.primaryDark]),
                borderRadius: BorderRadius.circular(10),
              ),
              child: const Icon(Icons.settings_outlined,
                  color: Colors.white, size: 18),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(tr.settings,
                      style: const TextStyle(
                          fontSize: 15, fontWeight: FontWeight.w800)),
                  Text(tr.settingsSubtitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontSize: 11,
                          color: Colors.white70,
                          fontWeight: FontWeight.w500)),
                ],
              ),
            ),
          ],
        ),
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
            children: [
              _profileCard(),
              const SizedBox(height: 20),
              _label(tr.appearance),
              _card(_appearance()),
              const SizedBox(height: 20),
              _label(tr.language),
              _card(_languageSection(), padding: EdgeInsets.zero),
              const SizedBox(height: 20),
              _label(tr.notifications),
              _card(_notificationSection(), padding: EdgeInsets.zero),
              const SizedBox(height: 20),
              _label(tr.security),
              _card(_securitySection(), padding: EdgeInsets.zero),
              const SizedBox(height: 20),
              _label(tr.about),
              _card(_aboutSection(), padding: EdgeInsets.zero),
              const SizedBox(height: 24),
              _logoutButton(),
            ],
          ),
        ),
      ),
    );
  }

  // ── Profile ───────────────────────────────────────────────────────────
  Widget _profileCard() {
    final r = widget.resident;
    final initials = [
      if (r.firstName.isNotEmpty) r.firstName[0],
      if (r.lastName.isNotEmpty) r.lastName[0],
    ].join().toUpperCase();
    return GestureDetector(
      onTap: _openProfile,
      child: Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [AppColors.heading2, AppColors.primary, AppColors.navy],
          ),
          borderRadius: BorderRadius.circular(24),
          boxShadow: [
            BoxShadow(
                color: AppColors.primary.withValues(alpha: .30),
                blurRadius: 24,
                offset: const Offset(0, 10)),
          ],
        ),
        child: Row(
          children: [
            ProfileAvatar(
                initials: initials.isEmpty ? '?' : initials,
                photoUrl: _photoUrl,
                size: 60,
                onGradient: true),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(r.fullName,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 18,
                          fontWeight: FontWeight.w900,
                          height: 1.15)),
                  if ((r.residentCode ?? '').isNotEmpty)
                    _profileLine(Icons.badge_outlined,
                        '${tr.residentCode}: ${r.residentCode}'),
                  if ((r.email ?? '').isNotEmpty)
                    _profileLine(Icons.mail_outline, r.email!),
                  if ((r.contactNumber ?? '').isNotEmpty)
                    _profileLine(Icons.call_outlined, r.contactNumber!),
                  if ((r.purok ?? '').isNotEmpty)
                    _profileLine(Icons.location_on_outlined, r.purok!),
                  const SizedBox(height: 8),
                  Row(children: [
                    Flexible(
                      child: Text(tr.viewProfile,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              color: Colors.white,
                              fontSize: 12,
                              fontWeight: FontWeight.w800)),
                    ),
                    const Icon(Icons.chevron_right,
                        size: 18, color: Colors.white),
                  ]),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _profileLine(IconData icon, String text) => Padding(
        padding: const EdgeInsets.only(top: 4),
        child: Row(children: [
          Icon(icon, size: 13, color: Colors.white.withValues(alpha: .7)),
          const SizedBox(width: 6),
          Expanded(
            child: Text(text,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    color: Colors.white.withValues(alpha: .8),
                    fontSize: 12,
                    fontWeight: FontWeight.w500)),
          ),
        ]),
      );

  // ── Appearance ────────────────────────────────────────────────────────
  Widget _appearance() {
    final mode = _settings.themeMode;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _subLabel(tr.theme),
        Row(
          children: [
            _modeOption(ThemeMode.light, Icons.light_mode_outlined,
                tr.themeLight, mode),
            const SizedBox(width: 8),
            _modeOption(
                ThemeMode.dark, Icons.dark_mode_outlined, tr.themeDark, mode),
            const SizedBox(width: 8),
            _modeOption(ThemeMode.system, Icons.brightness_auto_outlined,
                tr.themeSystem, mode),
          ],
        ),
        if (_settings.themeModeChoice == null) ...[
          const SizedBox(height: 6),
          Text(tr.themeFollowsBarangay,
              style: TextStyle(fontSize: 11, color: AppColors.slate400)),
        ],
        const SizedBox(height: 20),
        _subLabel(tr.accentColor),
        Wrap(
          spacing: 10,
          runSpacing: 10,
          children: [
            _swatch(null, AppColors.parseHex(_settings.portalAccent)!),
            // Skip the preset that is the same as the barangay default.
            for (final o in kAccentOptions)
              if (o.hex !=
                  AppColors.toHex(AppColors.parseHex(_settings.portalAccent)!))
                _swatch(o.hex, o.color),
          ],
        ),
        const SizedBox(height: 6),
        Text(
            _settings.accentChoice == null
                ? tr.accentBarangayDefault
                : tr.accentCustom,
            style: TextStyle(fontSize: 11, color: AppColors.slate400)),
        const SizedBox(height: 20),
        _subLabel(tr.textSize),
        Row(
          children: [
            _sizeOption(AppTextSize.small, tr.textSmall, 13),
            const SizedBox(width: 8),
            _sizeOption(AppTextSize.normal, tr.textNormal, 16),
            const SizedBox(width: 8),
            _sizeOption(AppTextSize.large, tr.textLarge, 20),
          ],
        ),
        const SizedBox(height: 16),
        _preview(),
      ],
    );
  }

  Widget _modeOption(
      ThemeMode value, IconData icon, String label, ThemeMode current) {
    final selected = _settings.themeModeChoice == value ||
        (_settings.themeModeChoice == null && current == value);
    return Expanded(
      child: _choice(
        selected: selected,
        onTap: () => _settings.setThemeMode(value),
        child: Column(
          children: [
            Icon(icon,
                size: 22,
                color: selected ? AppColors.primary : AppColors.slate400),
            const SizedBox(height: 6),
            Text(label,
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                    color: selected ? AppColors.primary : AppColors.slate500)),
          ],
        ),
      ),
    );
  }

  Widget _sizeOption(AppTextSize value, String label, double sample) {
    final selected = _settings.textSize == value;
    return Expanded(
      child: _choice(
        selected: selected,
        onTap: () => _settings.setTextSize(value),
        child: Column(
          children: [
            Text('Aa',
                textScaler: TextScaler.noScaling,
                style: TextStyle(
                    fontSize: sample,
                    fontWeight: FontWeight.w800,
                    color: selected ? AppColors.primary : AppColors.slate500)),
            const SizedBox(height: 4),
            Text(label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: selected ? AppColors.primary : AppColors.slate500)),
          ],
        ),
      ),
    );
  }

  Widget _choice(
      {required bool selected,
      required VoidCallback onTap,
      required Widget child}) {
    return InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
        decoration: BoxDecoration(
          color: selected
              ? AppColors.primary.withValues(alpha: .10)
              : AppColors.surfaceAlt,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
              color: selected ? AppColors.primary : AppColors.border,
              width: selected ? 1.6 : 1.2),
        ),
        child: child,
      ),
    );
  }

  Widget _swatch(String? hex, Color color) {
    final selected = _settings.accentChoice == hex;
    return Tooltip(
      message: hex ?? tr.accentBarangayDefault,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: () => _settings.setAccent(hex),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 150),
          width: 40,
          height: 40,
          padding: const EdgeInsets.all(3),
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            border: Border.all(
                color: selected ? color : Colors.transparent, width: 2.5),
          ),
          child: Container(
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
            child: selected
                ? const Icon(Icons.check, size: 18, color: Colors.white)
                : hex == null
                    ? const Icon(Icons.home_rounded,
                        size: 16, color: Colors.white)
                    : null,
          ),
        ),
      ),
    );
  }

  /// Small live preview of the chosen look.
  Widget _preview() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.scaffold,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: .12),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(Icons.palette_outlined, color: AppColors.primary),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr.previewTitle,
                    style: TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 13,
                        color: AppColors.slate800)),
                Text(tr.previewBody,
                    style:
                        TextStyle(fontSize: 11.5, color: AppColors.slate500)),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: AppTheme.primaryButton,
            child: Text(tr.previewButton,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 11,
                    fontWeight: FontWeight.w800)),
          ),
        ],
      ),
    );
  }

  // ── Language ──────────────────────────────────────────────────────────
  Widget _languageSection() {
    Widget option(AppLanguage lang, String title, String sub, String flag) {
      final selected = _settings.language == lang;
      return ListTile(
        onTap: () => _settings.setLanguage(lang),
        leading: Container(
          width: 38,
          height: 38,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: selected
                ? AppColors.primary.withValues(alpha: .12)
                : AppColors.muted,
            borderRadius: BorderRadius.circular(12),
          ),
          child: Text(flag,
              style: TextStyle(
                  fontWeight: FontWeight.w900,
                  fontSize: 12,
                  color: selected ? AppColors.primary : AppColors.slate500)),
        ),
        title: Text(title,
            style: TextStyle(
                fontWeight: FontWeight.w800,
                fontSize: 14,
                color: AppColors.slate800)),
        subtitle: Text(sub,
            style: TextStyle(fontSize: 12, color: AppColors.slate500)),
        trailing: Icon(
            selected ? Icons.radio_button_checked : Icons.radio_button_off,
            color: selected ? AppColors.primary : AppColors.slate400),
      );
    }

    return Material(
      type: MaterialType.transparency,
      child: Column(
        children: [
          option(AppLanguage.en, 'English', tr.languageEnglishSub, 'EN'),
          Divider(height: 1, color: AppColors.border),
          option(AppLanguage.fil, 'Filipino', tr.languageFilipinoSub, 'FIL'),
        ],
      ),
    );
  }

  // ── Security ──────────────────────────────────────────────────────────
  // ── Notifications: disaster alert sound ──────────────────────────────
  Widget _notificationSection() {
    const levels = <(String, Color)>[
      ('Low', Color(0xFF0EA5E9)),
      ('Medium', Color(0xFFD97706)),
      ('High', Color(0xFFE11D48)),
      ('Critical', Color(0xFFB91C1C)),
    ];
    return Material(
      type: MaterialType.transparency,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SwitchListTile(
            value: _settings.alertSound,
            onChanged: (v) async {
              await _settings.setAlertSound(v);
              if (!v) AlertSound.instance.stop();
              if (mounted) setState(() {});
            },
            secondary: _tileIcon(_settings.alertSound
                ? Icons.volume_up_outlined
                : Icons.volume_off_outlined),
            title: Text(tr.alertSound, style: _tileTitle),
            subtitle: Text(tr.alertSoundSub, style: _tileSub),
          ),
          Divider(height: 1, color: AppColors.border),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr.testAlertSound, style: _tileTitle),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    for (final l in levels)
                      OutlinedButton.icon(
                        onPressed: () =>
                            AlertSound.instance.play(l.$1, force: true),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: l.$2,
                          side: BorderSide(color: l.$2.withValues(alpha: .5)),
                          visualDensity: VisualDensity.compact,
                        ),
                        icon: const Icon(Icons.play_arrow_rounded, size: 18),
                        label: Text(tr.severityLabel(l.$1)),
                      ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _securitySection() {
    return Material(
      type: MaterialType.transparency,
      child: Column(
        children: [
          _navTile(Icons.lock_reset, tr.changePassword, tr.changePasswordSub,
              () {
            Navigator.of(context).push(MaterialPageRoute(
                builder: (_) =>
                    ChangePasswordScreen(resident: widget.resident)));
          }),
          Divider(height: 1, color: AppColors.border),
          _navTile(Icons.pin_outlined, _hasPin ? tr.changePin : tr.setUpPin,
              tr.changePinSub, _openChangePin),
          if (_biometricAvailable && _hasPin) ...[
            Divider(height: 1, color: AppColors.border),
            SwitchListTile(
              value: _biometricOn,
              onChanged: _toggleBiometric,
              secondary: _tileIcon(Icons.fingerprint),
              title: Text(tr.biometricUnlock, style: _tileTitle),
              subtitle: Text(tr.biometricUnlockSub, style: _tileSub),
            ),
          ],
          Divider(height: 1, color: AppColors.border),
          ListTile(
            leading: _tileIcon(Icons.schedule),
            title: Text(tr.stayedSignedIn, style: _tileTitle),
            subtitle: Text(
                _sessionUntil == null
                    ? tr.sessionNotRemembered
                    : tr.sessionUntil(_fmtDate(_sessionUntil!)),
                style: _tileSub),
          ),
          Divider(height: 1, color: AppColors.border),
          _navTile(Icons.devices_other_outlined, tr.logoutAllDevices,
              tr.logoutAllDevicesSub, _logoutAll),
        ],
      ),
    );
  }

  // ── About ─────────────────────────────────────────────────────────────
  Widget _aboutSection() {
    return Material(
      type: MaterialType.transparency,
      child: Column(
        children: [
          ListTile(
            leading: _tileIcon(Icons.info_outline),
            title: Text(tr.appName, style: _tileTitle),
            subtitle: Text('${tr.version} $appVersion', style: _tileSub),
          ),
          Divider(height: 1, color: AppColors.border),
          ListTile(
            leading: _tileIcon(Icons.support_agent),
            title: Text(tr.needHelp, style: _tileTitle),
            subtitle: Text(tr.needHelpSub, style: _tileSub),
          ),
          Divider(height: 1, color: AppColors.border),
          _navTile(Icons.restart_alt, tr.resetAppearanceTitle,
              tr.resetAppearanceSub, _resetAppearance),
        ],
      ),
    );
  }

  Widget _logoutButton() {
    return OutlinedButton.icon(
      onPressed: _logout,
      style: OutlinedButton.styleFrom(
        foregroundColor: AppColors.danger,
        side: BorderSide(color: AppColors.danger.withValues(alpha: .5)),
        padding: const EdgeInsets.symmetric(vertical: 14),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
      icon: const Icon(Icons.logout),
      label:
          Text(tr.logout, style: const TextStyle(fontWeight: FontWeight.w800)),
    );
  }

  // ── helpers ───────────────────────────────────────────────────────────
  TextStyle get _tileTitle => TextStyle(
      fontWeight: FontWeight.w800, fontSize: 14, color: AppColors.slate800);
  TextStyle get _tileSub => TextStyle(fontSize: 12, color: AppColors.slate500);

  Widget _tileIcon(IconData icon) => Container(
        width: 38,
        height: 38,
        decoration: BoxDecoration(
          color: AppColors.primary.withValues(alpha: .10),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Icon(icon, size: 20, color: AppColors.primary),
      );

  Widget _navTile(
          IconData icon, String title, String sub, VoidCallback onTap) =>
      ListTile(
        onTap: onTap,
        leading: _tileIcon(icon),
        title: Text(title, style: _tileTitle),
        subtitle: Text(sub, style: _tileSub),
        trailing: Icon(Icons.chevron_right, color: AppColors.slate400),
      );

  Widget _label(String s) => Padding(
        padding: const EdgeInsets.only(left: 4, bottom: 8),
        child: Text(s.toUpperCase(),
            style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w900,
                letterSpacing: .8,
                color: AppColors.heading2)),
      );

  Widget _subLabel(String s) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(s,
            style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w800,
                color: AppColors.slate800)),
      );

  Widget _card(Widget child, {EdgeInsetsGeometry? padding}) => Container(
        padding: padding ?? const EdgeInsets.all(18),
        clipBehavior: Clip.antiAlias,
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: AppColors.border),
          boxShadow: [
            BoxShadow(
                color: AppColors.primary.withValues(alpha: .06),
                blurRadius: 18,
                offset: const Offset(0, 6)),
          ],
        ),
        child: child,
      );

  String _fmtDate(DateTime d) {
    final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
    final m = d.minute.toString().padLeft(2, '0');
    return '${tr.monthsShort[d.month - 1]} ${d.day}, ${d.year} · '
        '$h:$m ${d.hour < 12 ? 'AM' : 'PM'}';
  }
}
