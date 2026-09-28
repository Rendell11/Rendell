import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../l10n/app_text.dart';
import '../services/api_service.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import '../widgets/password_rules.dart';
import '../widgets/wave_background.dart';

/// Forgot password in two steps (user/backend/forgot_password.php):
///   1. the registered email → the barangay emails a 6-digit code
///   2. the code + a new password (same rules as Set Password)
/// On success every device is logged out and the resident logs in again.
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  static const _resendSeconds = 60;

  final _api = ApiService();
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  bool _sending = false;
  bool _saving = false;
  bool _codeStep = false;
  bool _obscure = true;
  int _cooldown = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _email.dispose();
    _code.dispose();
    _password.dispose();
    _confirm.dispose();
    _api.dispose();
    super.dispose();
  }

  void _snack(String msg, {bool error = false}) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        content: Text(msg),
        backgroundColor: error ? AppColors.danger : null,
      ));
  }

  void _startCooldown() {
    _timer?.cancel();
    setState(() => _cooldown = _resendSeconds);
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return t.cancel();
      setState(() => _cooldown--);
      if (_cooldown <= 0) t.cancel();
    });
  }

  Future<void> _send({bool resend = false}) async {
    final email = _email.text.trim();
    if (!RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(email)) {
      _snack(tr.enterValidEmail, error: true);
      return;
    }
    setState(() => _sending = true);
    final res = await _api.forgotPassword(email);
    if (!mounted) return;
    setState(() => _sending = false);
    if (!res.ok) {
      _snack(res.message, error: true);
      return;
    }
    setState(() => _codeStep = true);
    _startCooldown();
    if (resend) _snack(tr.fpResent);
  }

  Future<void> _reset() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final res = await _api.resetPasswordWithCode(
      email: _email.text.trim(),
      code: _code.text.trim(),
      password: _password.text,
    );
    if (!mounted) return;
    setState(() => _saving = false);
    _snack(res.message, error: !res.ok);
    if (res.ok) Navigator.of(context).pop();
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
                  child: _codeStep ? _codeView() : _emailView(),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _title(String title, String sub) => Column(
        children: [
          const BrandHeader(),
          const SizedBox(height: 14),
          Text(title.toUpperCase(),
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.w700,
                  color: AppColors.heading2)),
          const SizedBox(height: 4),
          Text(sub,
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 13, color: AppColors.slate400)),
          const SizedBox(height: 20),
        ],
      );

  Widget _info(String text) => Container(
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
              child: Text(text,
                  style: TextStyle(fontSize: 12, color: AppColors.infoText2)),
            ),
          ],
        ),
      );

  Widget _back() => TextButton.icon(
        onPressed: () => Navigator.of(context).pop(),
        icon: const Icon(Icons.arrow_back, size: 16),
        label: Text(tr.backToLogin),
      );

  Widget _label(String s) => Align(
        alignment: Alignment.centerLeft,
        child: FieldLabel(s, required: true),
      );

  Widget _emailView() {
    return Column(
      children: [
        _title(tr.forgotPassword, tr.forgotPasswordSub),
        _label(tr.emailAddress),
        TextField(
          controller: _email,
          keyboardType: TextInputType.emailAddress,
          autocorrect: false,
          decoration:
              AppTheme.field(tr.enterRegisteredEmail, icon: Icons.mail_outline),
          onSubmitted: (_) => _send(),
        ),
        const SizedBox(height: 14),
        _info(tr.resetLinkExpiry),
        const SizedBox(height: 20),
        PrimaryButton(
          label: tr.sendResetLink,
          icon: Icons.send,
          loading: _sending,
          onPressed: _send,
        ),
        const SizedBox(height: 12),
        _back(),
      ],
    );
  }

  Widget _codeView() {
    return Form(
      key: _formKey,
      child: Column(
        children: [
          _title(tr.fpEnterCode, tr.fpEnterCodeSub(_email.text.trim())),
          _label(tr.fpCode),
          TextFormField(
            controller: _code,
            keyboardType: TextInputType.number,
            textAlign: TextAlign.center,
            maxLength: 6,
            inputFormatters: [FilteringTextInputFormatter.digitsOnly],
            style: const TextStyle(
                fontSize: 22, fontWeight: FontWeight.w700, letterSpacing: 8),
            decoration: AppTheme.field('000000', icon: Icons.pin_outlined)
                .copyWith(counterText: ''),
            validator: (v) =>
                (v ?? '').trim().length != 6 ? tr.fpCodeInvalid : null,
          ),
          const SizedBox(height: 14),
          _label(tr.newPassword),
          TextFormField(
            controller: _password,
            obscureText: _obscure,
            decoration: AppTheme.field(tr.atLeast8,
                icon: Icons.lock_outline,
                suffix: IconButton(
                  icon: Icon(_obscure ? Icons.visibility_off : Icons.visibility,
                      size: 20, color: AppColors.slate400),
                  onPressed: () => setState(() => _obscure = !_obscure),
                )),
            validator: PasswordPolicy.validate,
          ),
          PasswordRules(controller: _password),
          const SizedBox(height: 14),
          _label(tr.confirmPassword),
          TextFormField(
            controller: _confirm,
            obscureText: _obscure,
            decoration:
                AppTheme.field(tr.retypePassword, icon: Icons.lock_outline),
            validator: (v) =>
                v != _password.text ? tr.passwordsDontMatch : null,
          ),
          const SizedBox(height: 22),
          PrimaryButton(
            label: tr.savePassword,
            icon: Icons.check,
            loading: _saving,
            onPressed: _reset,
          ),
          const SizedBox(height: 12),
          _info(tr.fpNoEmail),
          const SizedBox(height: 4),
          Wrap(
            alignment: WrapAlignment.center,
            children: [
              TextButton.icon(
                onPressed: _cooldown > 0 || _sending
                    ? null
                    : () => _send(resend: true),
                icon: const Icon(Icons.refresh, size: 16),
                label: Text(
                    _cooldown > 0 ? tr.fpResendIn(_cooldown) : tr.fpResend),
              ),
              TextButton.icon(
                onPressed: () => setState(() {
                  _codeStep = false;
                  _code.clear();
                }),
                icon: const Icon(Icons.alternate_email, size: 16),
                label: Text(tr.fpOtherEmail),
              ),
            ],
          ),
          _back(),
        ],
      ),
    );
  }
}
