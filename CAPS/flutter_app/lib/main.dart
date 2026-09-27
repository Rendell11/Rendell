import 'package:flutter/material.dart';

import 'screens/auth_gate.dart';
import 'services/api_service.dart';
import 'theme/app_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Pull the admin-configured accent (settings.php) so the pre-login screens
  // follow the barangay's chosen colour. Fails safe to brand blue.
  try {
    final api = ApiService();
    final theme = await api.fetchPublicTheme();
    AppColors.applyAccent(theme.accent);
    api.dispose();
  } catch (_) {
    // keep default brand blue
  }

  runApp(const ResidentPortalApp());
}

class ResidentPortalApp extends StatelessWidget {
  const ResidentPortalApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Barangay Biñang 2nd — Resident Portal',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light,
      home: const AuthGate(),
    );
  }
}
