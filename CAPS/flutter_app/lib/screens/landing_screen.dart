import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../theme/app_theme.dart';
import 'login_screen.dart';
import 'request_access_screen.dart';
import 'set_password_screen.dart';
import 'status_screen.dart';

/// Entry menu that routes to each step of the resident access flow.
class LandingScreen extends StatelessWidget {
  const LandingScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 24),
              const Icon(Icons.location_city, size: 72),
              const SizedBox(height: 12),
              Text(
                tr.appName,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 4),
              Text(
                tr.selfServiceAccess,
                textAlign: TextAlign.center,
                style: TextStyle(color: AppColors.slate500),
              ),
              const SizedBox(height: 40),
              _menuButton(
                context,
                icon: Icons.person_add_alt,
                label: tr.requestAccess,
                screen: const RequestAccessScreen(),
                filled: true,
              ),
              const SizedBox(height: 12),
              _menuButton(
                context,
                icon: Icons.search,
                label: tr.trackRequestStatus,
                screen: const StatusScreen(),
              ),
              const SizedBox(height: 12),
              _menuButton(
                context,
                icon: Icons.lock_outline,
                label: tr.setPasswordWithToken,
                screen: const SetPasswordScreen(),
              ),
              const SizedBox(height: 12),
              _menuButton(
                context,
                icon: Icons.login,
                label: tr.signIn,
                screen: const LoginScreen(),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _menuButton(
    BuildContext context, {
    required IconData icon,
    required String label,
    required Widget screen,
    bool filled = false,
  }) {
    final onPressed = () => Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => screen),
        );
    final child = Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [Icon(icon), const SizedBox(width: 8), Flexible(child: Text(label))],
    );
    return filled
        ? FilledButton(
            onPressed: onPressed,
            style: FilledButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 16)),
            child: child,
          )
        : OutlinedButton(
            onPressed: onPressed,
            style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 16)),
            child: child,
          );
  }
}
