import 'package:flutter/material.dart';

import '../models/resident.dart';
import '../services/biometric_service.dart';
import '../services/session_service.dart';
import '../l10n/app_text.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import '../widgets/pin_pad.dart';
import '../widgets/wave_background.dart';
import '../dashboard/dashboard_screen.dart';

/// Shown once, right after the first successful login: the resident sets a
/// 6-digit PIN and optionally turns on biometric unlock. After this, opening
/// the app only asks for the PIN or biometrics (within the 7-day window).
class SetupPinScreen extends StatefulWidget {
  const SetupPinScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<SetupPinScreen> createState() => _SetupPinScreenState();
}

class _SetupPinScreenState extends State<SetupPinScreen> {
  final _session = SessionService();
  final _biometric = BiometricService();
  final _padKey = GlobalKey<PinPadState>();

  String? _firstPin;
  String? _error;
  bool _biometricAvailable = false;
  bool _enableBiometric = true;

  @override
  void initState() {
    super.initState();
    _biometric.isAvailable().then((v) {
      if (mounted) setState(() => _biometricAvailable = v);
    });
  }

  Future<void> _onCompleted(String pin) async {
    if (_firstPin == null) {
      setState(() {
        _firstPin = pin;
        _error = null;
      });
      _padKey.currentState?.reset();
      return;
    }
    if (pin != _firstPin) {
      setState(() {
        _error = tr.pinMismatch;
        _firstPin = null;
      });
      _padKey.currentState?.reset();
      return;
    }
    // Confirmed — save.
    await _session.setPin(pin);
    await _session.setBiometricEnabled(_biometricAvailable && _enableBiometric);
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => DashboardScreen(resident: widget.resident)),
      (_) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    final confirming = _firstPin != null;
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
                          badgeText: tr.secureAccess,
                          badgeIcon: Icons.lock),
                      const SizedBox(height: 16),
                      Text(
                          (confirming ? tr.confirmPin : tr.createPin)
                              .toUpperCase(),
                          style: TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w700,
                              color: AppColors.heading2)),
                      const SizedBox(height: 4),
                      Text(
                          confirming ? tr.confirmPinSub : tr.createPinSub,
                          textAlign: TextAlign.center,
                          style: TextStyle(
                              fontSize: 13, color: AppColors.slate400)),
                      const SizedBox(height: 24),
                      PinPad(
                        key: _padKey,
                        onCompleted: _onCompleted,
                        errorText: _error,
                      ),
                      if (_biometricAvailable && !confirming) ...[
                        const SizedBox(height: 12),
                        SwitchListTile(
                          contentPadding: EdgeInsets.zero,
                          value: _enableBiometric,
                          onChanged: (v) =>
                              setState(() => _enableBiometric = v),
                          title: Text(tr.enableBiometrics,
                              style: const TextStyle(
                                  fontSize: 14, fontWeight: FontWeight.w600)),
                          subtitle: Text(
                              tr.enableBiometricsSub,
                              style: TextStyle(
                                  fontSize: 12, color: AppColors.slate400)),
                          secondary: Icon(Icons.fingerprint,
                              color: AppColors.primary),
                        ),
                      ],
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
