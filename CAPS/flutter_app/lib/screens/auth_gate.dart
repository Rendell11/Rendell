import 'package:flutter/material.dart';

import '../services/session_service.dart';
import '../theme/app_theme.dart';
import '../widgets/wave_background.dart';
import 'lock_screen.dart';
import 'login_screen.dart';
import 'setup_pin_screen.dart';

/// Decides the first screen on launch:
///  - valid session + PIN set  → LockScreen (biometric / PIN unlock)
///  - valid session, no PIN yet → SetupPinScreen
///  - otherwise                 → LoginScreen (full login)
class AuthGate extends StatefulWidget {
  const AuthGate({super.key});

  @override
  State<AuthGate> createState() => _AuthGateState();
}

class _AuthGateState extends State<AuthGate> {
  final _session = SessionService();
  late final Future<Widget> _decision = _decide();

  Future<Widget> _decide() async {
    if (await _session.hasValidSession()) {
      final resident = await _session.currentResident();
      if (resident != null) {
        if (await _session.hasPin()) return LockScreen(resident: resident);
        return SetupPinScreen(resident: resident);
      }
    }
    return const LoginScreen();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Widget>(
      future: _decision,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return Scaffold(
            body: WaveBackground(
              child: Center(
                child: CircularProgressIndicator(color: AppColors.primary),
              ),
            ),
          );
        }
        return snap.data ?? const LoginScreen();
      },
    );
  }
}
