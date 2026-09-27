import 'package:flutter/material.dart';

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
          const SnackBar(content: Text('Ilagay ang wastong email address.')));
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
        Text('FORGOT PASSWORD',
            style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: AppColors.heading2)),
        const SizedBox(height: 4),
        Text(
            "Enter your registered email address and we'll send you a reset link.",
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 13, color: AppColors.slate400)),
        const SizedBox(height: 20),
        Align(
          alignment: Alignment.centerLeft,
          child: FieldLabel('Email Address', required: true),
        ),
        TextField(
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          decoration: AppTheme.field('Enter your registered email address',
              icon: Icons.mail_outline),
        ),
        const SizedBox(height: 14),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: const Color(0xFFEFF6FF),
            border: Border.all(color: const Color(0xFFBFDBFE)),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(Icons.info_outline, size: 18, color: AppColors.accent),
              const SizedBox(width: 8),
              const Expanded(
                child: Text(
                    'The reset link will expire in 1 hour. Check your spam folder if you don\'t see the email.',
                    style: TextStyle(fontSize: 12, color: Color(0xFF1D4ED8))),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        PrimaryButton(
          label: 'Send Reset Link',
          icon: Icons.send,
          loading: _sending,
          onPressed: _send,
        ),
        const SizedBox(height: 12),
        TextButton.icon(
          onPressed: () => Navigator.of(context).pop(),
          icon: const Icon(Icons.arrow_back, size: 16),
          label: const Text('Back to Login'),
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
            color: const Color(0xFFECFDF5),
            shape: BoxShape.circle,
            border: Border.all(color: const Color(0xFFA7F3D0), width: 2),
          ),
          child: const Icon(Icons.mark_email_read,
              size: 44, color: AppColors.success),
        ),
        const SizedBox(height: 16),
        Text('CHECK YOUR EMAIL',
            style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: AppColors.heading2)),
        const SizedBox(height: 8),
        Text(
            'If that email is registered, you will receive a password reset link shortly. Please check your inbox (and spam folder).',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 13, color: AppColors.slate500)),
        const SizedBox(height: 20),
        PrimaryButton(
          label: 'Back to Login',
          icon: Icons.arrow_back,
          onPressed: () => Navigator.of(context).pop(),
        ),
      ],
    );
  }
}
