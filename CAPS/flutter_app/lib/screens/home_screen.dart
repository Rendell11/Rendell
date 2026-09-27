import 'package:flutter/material.dart';

import '../models/resident.dart';
import '../services/session_service.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import '../widgets/wave_background.dart';
import 'login_screen.dart';

/// Post-login landing. Wire your real resident dashboard here. Includes a
/// logout that clears the saved session + PIN.
class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key, required this.resident});

  final Resident resident;

  Future<void> _logout(BuildContext context) async {
    await SessionService().clear();
    if (!context.mounted) return;
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
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: GlassCard(
                  padding: const EdgeInsets.all(28),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const BrandHeader(),
                      const SizedBox(height: 16),
                      Icon(Icons.verified_user,
                          size: 56, color: AppColors.success),
                      const SizedBox(height: 12),
                      Text('Kumusta, ${resident.fullName}!',
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                              fontSize: 18, fontWeight: FontWeight.w700)),
                      if (resident.residentCode != null)
                        Text('Resident Code: ${resident.residentCode}',
                            style: TextStyle(color: AppColors.slate500)),
                      const SizedBox(height: 8),
                      Text('Aktibo na ang iyong resident portal account.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: AppColors.slate500)),
                      const SizedBox(height: 24),
                      OutlinedButton.icon(
                        onPressed: () => _logout(context),
                        icon: const Icon(Icons.logout, size: 18),
                        label: const Text('Mag-logout'),
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
