import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../services/api_service.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import '../widgets/password_rules.dart';
import '../widgets/wave_background.dart';

/// Set / reset password using the token issued after approval (or from the
/// reset link). Matches SOE styling; posts to set_password.php.
class SetPasswordScreen extends StatefulWidget {
  const SetPasswordScreen({super.key, this.prefillToken});

  final String? prefillToken;

  @override
  State<SetPasswordScreen> createState() => _SetPasswordScreenState();
}

class _SetPasswordScreenState extends State<SetPasswordScreen> {
  final _api = ApiService();
  final _formKey = GlobalKey<FormState>();
  final _token = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  bool _obscure = true;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    if (widget.prefillToken != null) _token.text = widget.prefillToken!;
  }

  @override
  void dispose() {
    _token.dispose();
    _password.dispose();
    _confirm.dispose();
    _api.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final res = await _api.setPassword(
        token: _token.text.trim(), password: _password.text);
    if (!mounted) return;
    setState(() => _saving = false);
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(res.message)));
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
                  child: Form(
                    key: _formKey,
                    child: Column(
                      children: [
                        const BrandHeader(),
                        const SizedBox(height: 14),
                        Text(tr.setPassword.toUpperCase(),
                            style: TextStyle(
                                fontSize: 20,
                                fontWeight: FontWeight.w700,
                                color: AppColors.heading2)),
                        const SizedBox(height: 4),
                        Text(tr.setPasswordSub,
                            textAlign: TextAlign.center,
                            style: TextStyle(
                                fontSize: 13, color: AppColors.slate400)),
                        const SizedBox(height: 20),
                        _label(tr.accessToken),
                        TextFormField(
                          controller: _token,
                          decoration: AppTheme.field(tr.pasteToken,
                              icon: Icons.vpn_key_outlined),
                          validator: (v) => (v == null || v.trim().isEmpty)
                              ? tr.required
                              : null,
                        ),
                        const SizedBox(height: 14),
                        _label(tr.newPassword),
                        TextFormField(
                          controller: _password,
                          obscureText: _obscure,
                          decoration: AppTheme.field(tr.atLeast8,
                              icon: Icons.lock_outline,
                              suffix: IconButton(
                                icon: Icon(
                                    _obscure
                                        ? Icons.visibility_off
                                        : Icons.visibility,
                                    size: 20,
                                    color: AppColors.slate400),
                                onPressed: () =>
                                    setState(() => _obscure = !_obscure),
                              )),
                          validator: PasswordPolicy.validate,
                        ),
                        PasswordRules(controller: _password),
                        const SizedBox(height: 14),
                        _label(tr.confirmPassword),
                        TextFormField(
                          controller: _confirm,
                          obscureText: _obscure,
                          decoration: AppTheme.field(tr.retypePassword,
                              icon: Icons.lock_outline),
                          validator: (v) => v != _password.text
                              ? tr.passwordsDontMatch
                              : null,
                        ),
                        const SizedBox(height: 22),
                        PrimaryButton(
                          label: tr.savePassword,
                          icon: Icons.check,
                          loading: _saving,
                          onPressed: _submit,
                        ),
                        const SizedBox(height: 12),
                        TextButton.icon(
                          onPressed: () => Navigator.of(context).pop(),
                          icon: const Icon(Icons.arrow_back, size: 16),
                          label: Text(tr.backToLogin),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _label(String s) => Align(
        alignment: Alignment.centerLeft,
        child: FieldLabel(s, required: true),
      );
}
