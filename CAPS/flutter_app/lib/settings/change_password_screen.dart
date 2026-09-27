import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import 'settings_api.dart';

/// Settings → Change password (current + new + confirm).
class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _api = SettingsApi();
  final _formKey = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _new = TextEditingController();
  final _confirm = TextEditingController();
  bool _obscure = true;
  bool _saving = false;

  @override
  void dispose() {
    _current.dispose();
    _new.dispose();
    _confirm.dispose();
    _api.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final res = await _api.changePassword(
      residentId: widget.resident.residentId,
      currentPassword: _current.text,
      newPassword: _new.text,
    );
    if (!mounted) return;
    setState(() => _saving = false);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(res.message),
      backgroundColor: res.ok ? null : AppColors.danger,
    ));
    if (res.ok) Navigator.of(context).pop(true);
  }

  Widget _eye() => IconButton(
        icon: Icon(_obscure ? Icons.visibility_off : Icons.visibility,
            size: 20, color: AppColors.slate400),
        onPressed: () => setState(() => _obscure = !_obscure),
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: AppBar(
        backgroundColor: AppColors.appBar,
        foregroundColor: Colors.white,
        title: Text(tr.changePassword,
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 480),
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: GlassCard(
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(tr.changePasswordIntro,
                        style: TextStyle(
                            fontSize: 13,
                            color: AppColors.slate500,
                            height: 1.4)),
                    const SizedBox(height: 18),
                    FieldLabel(tr.currentPassword, required: true),
                    TextFormField(
                      controller: _current,
                      obscureText: _obscure,
                      decoration: AppTheme.field(tr.currentPasswordHint,
                          icon: Icons.lock_outline, suffix: _eye()),
                      validator: (v) =>
                          (v == null || v.isEmpty) ? tr.required : null,
                    ),
                    const SizedBox(height: 14),
                    FieldLabel(tr.newPassword, required: true),
                    TextFormField(
                      controller: _new,
                      obscureText: _obscure,
                      decoration:
                          AppTheme.field(tr.atLeast8, icon: Icons.lock_reset),
                      validator: (v) {
                        if (v == null || v.length < 8) return tr.atLeast8Error;
                        if (v == _current.text) return tr.newPasswordSame;
                        return null;
                      },
                    ),
                    const SizedBox(height: 14),
                    FieldLabel(tr.confirmPassword, required: true),
                    TextFormField(
                      controller: _confirm,
                      obscureText: _obscure,
                      decoration: AppTheme.field(tr.retypePassword,
                          icon: Icons.lock_reset),
                      validator: (v) =>
                          v != _new.text ? tr.passwordsDontMatch : null,
                    ),
                    const SizedBox(height: 22),
                    PrimaryButton(
                      label: tr.savePassword,
                      icon: Icons.check,
                      loading: _saving,
                      onPressed: _submit,
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
