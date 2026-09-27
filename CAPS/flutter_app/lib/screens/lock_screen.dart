import 'package:flutter/material.dart';

import '../models/resident.dart';
import '../services/biometric_service.dart';
import '../services/session_service.dart';
import '../l10n/app_text.dart';
import '../settings/app_settings.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import '../widgets/pin_pad.dart';
import '../widgets/wave_background.dart';
import '../dashboard/dashboard_screen.dart';
import 'login_screen.dart';

/// The bank-style unlock screen shown when the app opens with a valid session.
/// Tries biometrics first (if enabled), with the 6-digit PIN as fallback.
class LockScreen extends StatefulWidget {
  const LockScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends State<LockScreen> {
  final _session = SessionService();
  final _biometric = BiometricService();
  final _padKey = GlobalKey<PinPadState>();

  String? _error;
  int _attempts = 0;
  bool _biometricEnabled = false;

  @override
  void initState() {
    super.initState();
    _maybeBiometric();
  }

  Future<void> _maybeBiometric() async {
    _biometricEnabled = await _session.biometricEnabled();
    if (mounted) setState(() {});
    if (_biometricEnabled && await _biometric.isAvailable()) {
      final ok = await _biometric.authenticate();
      if (ok) _unlock();
    }
  }

  Future<void> _onPin(String pin) async {
    if (await _session.verifyPin(pin)) {
      _unlock();
    } else {
      setState(() {
        _attempts++;
        _error = tr.wrongPin;
      });
      _padKey.currentState?.reset();
    }
  }

  void _unlock() {
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => DashboardScreen(resident: widget.resident)),
      (_) => false,
    );
  }

  Future<void> _logout() async {
    await _session.clear();
    AppSettings.instance.unbindResident();
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (_) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: WaveBackground(
        child: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(16, 24, 16, 40),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: GlassCard(
                  padding: const EdgeInsets.all(28),
                  child: Column(
                    children: [
                      BrandHeader(
                          badgeText: tr.locked, badgeIcon: Icons.lock),
                      const SizedBox(height: 14),
                      Text(tr.helloName(widget.resident.firstName),
                          style: const TextStyle(
                              fontSize: 18, fontWeight: FontWeight.w700)),
                      const SizedBox(height: 2),
                      Text(tr.enterPinToContinue,
                          style: TextStyle(
                              fontSize: 13, color: AppColors.slate400)),
                      const SizedBox(height: 24),
                      PinPad(
                        key: _padKey,
                        onCompleted: _onPin,
                        errorText: _error,
                        showBiometricButton: _biometricEnabled,
                        onBiometric: _maybeBiometric,
                      ),
                      const SizedBox(height: 16),
                      TextButton(
                        onPressed: _logout,
                        child: Text(tr.notYouLogin),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
