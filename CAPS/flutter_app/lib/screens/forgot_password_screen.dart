import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../services/api_service.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import '../widgets/wave_background.dart';

/// Forgot password — matches SOE `resident_forgot_password.php`. Sends a reset
/// request; the backend emails a reset link (residents.ResetToken/TokenExpiry).
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _api = ApiService();
  final _email = TextEditingController();
  bool _sending = false;
  bool _sent = false;

  @override
  void dispose() {
    _email.dispose();
    _api.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final email = _email.text.trim();
    if (!RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(email)) {
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(tr.enterValidEmail)));
      return;
    }
    setState(() => _sending = true);
    await _api.forgotPassword(email);
    if (!mounted) return;
    // Always show success (anti-enumeration, same as SOE).
    setState(() {
      _sending = false;
      _sent = true;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: WaveBackground(
        child: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(16, 24, 16, 60),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 460),
                child: GlassCard(
                  padding: const EdgeInsets.all(28),
                  child: _sent ? _sentView() : _formView(),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _formView() {
    return Column(
      children: [
        const BrandHeader(),
        const SizedBox(height: 14),
        Text(tr.forgotPassword.toUpperCase(),
            style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: AppColors.heading2)),
        const SizedBox(height: 4),
        Text(
            tr.forgotPasswordSub,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 13, color: AppColors.slate400)),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerLeft,
          child: FieldLabel(tr.emailAddress, required: true),
        ),
        TextField(
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          decoration: AppTheme.field(tr.enterRegisteredEmail,
              icon: Icons.mail_outline),
        ),
        const SizedBox(height: 14),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: AppColors.infoBg,
            border: Border.all(color: AppColors.infoBorder),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(Icons.info_outline, size: 18, color: AppColors.accent),
              const SizedBox(width: 8),
              Expanded(
                child: Text(tr.resetLinkExpiry,
                    style: TextStyle(fontSize: 12, color: AppColors.infoText2)),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        PrimaryButton(
          label: tr.sendResetLink,
          icon: Icons.send,
          loading: _sending,
          onPressed: _send,
        ),
        const SizedBox(height: 12),
        TextButton.icon(
          onPressed: () => Navigator.of(context).pop(),
          icon: const Icon(Icons.arrow_back, size: 16),
          label: Text(tr.backToLogin),
        ),
      ],
    );
  }

  Widget _sentView() {
    return Column(
      children: [
        const BrandHeader(),
        const SizedBox(height: 20),
        Container(
          width: 80,
          height: 80,
          decoration: BoxDecoration(
            color: AppColors.successBg,
            shape: BoxShape.circle,
            border: Border.all(color: AppColors.successBorder, width: 2),
          ),
          child: const Icon(Icons.mark_email_read,
              size: 44, color: AppColors.success),
        ),
        const SizedBox(height: 16),
        Text(tr.checkYourEmail.toUpperCase(),
            style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: AppColors.heading2)),
        const SizedBox(height: 8),
        Text(
            tr.checkYourEmailSub,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 13, color: AppColors.slate500)),
        const SizedBox(height: 20),
        PrimaryButton(
          label: tr.backToLogin,
          icon: Icons.arrow_back,
          onPressed: () => Navigator.of(context).pop(),
        ),
      ],
    );
  }
}
