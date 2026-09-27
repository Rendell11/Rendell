import 'package:flutter/material.dart';

import '../models/access_request.dart';
import '../services/api_service.dart';
import '../services/session_service.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import '../widgets/wave_background.dart';
import 'forgot_password_screen.dart';
import '../dashboard/dashboard_screen.dart';
import 'request_access_screen.dart';
import 'set_password_screen.dart';
import 'setup_pin_screen.dart';

/// Resident login — the app's home screen, matching SOE `resident_login.php`
/// (login card + "Track Request Status" section + Request Access link).
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _api = ApiService();
  final _session = SessionService();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _trackEmail = TextEditingController();
  bool _obscure = true;
  bool _remember = true;
  bool _loading = false;
  bool _tracking = false;
  AccessRequest? _tracked;
  String? _trackError;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _trackEmail.dispose();
    _api.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    if (_email.text.trim().isEmpty || _password.text.isEmpty) {
      _snack('Ilagay ang email at password.');
      return;
    }
    setState(() => _loading = true);
    final res = await _api.login(
      identifier: _email.text.trim(),
      password: _password.text,
    );
    if (!mounted) return;
    setState(() => _loading = false);
    if (res.ok && res.data != null) {
      final resident = res.data!;
      // "Remember me" → keep a 7-day session and set up quick unlock (PIN/biometrics).
      if (_remember) {
        await _session.startSession(resident);
        if (!mounted) return;
        // Only ask to CREATE a PIN if one isn't set yet. If a PIN already
        // exists, the resident just authenticated with their password, so go
        // straight to the dashboard (no need to re-create the PIN every login).
        final hasPin = await _session.hasPin();
        if (!mounted) return;
        Navigator.of(context).pushAndRemoveUntil(
          MaterialPageRoute(
            builder: (_) => hasPin
                ? DashboardScreen(resident: resident)
                : SetupPinScreen(resident: resident),
          ),
          (_) => false,
        );
      } else {
        Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => DashboardScreen(resident: resident)),
        );
      }
    } else {
      _snack(res.message);
    }
  }

  Future<void> _track() async {
    final email = _trackEmail.text.trim();
    if (email.isEmpty) {
      setState(() => _trackError = 'Ilagay ang email address.');
      return;
    }
    setState(() {
      _tracking = true;
      _trackError = null;
      _tracked = null;
    });
    final res = await _api.checkStatus(email: email);
    if (!mounted) return;
    setState(() {
      _tracking = false;
      if (res.ok) {
        _tracked = res.data;
      } else {
        _trackError = res.message;
      }
    });
  }

  void _snack(String m) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: WaveBackground(
        child: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(16, 24, 16, 60),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: Column(
                  children: [
                    GlassCard(
                      padding: const EdgeInsets.all(28),
                      child: Column(
                        children: [
                          const BrandHeader(),
                          const SizedBox(height: 14),
                          Text('RESIDENT LOGIN',
                              style: TextStyle(
                                  fontSize: 20,
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.heading2)),
                          const SizedBox(height: 2),
                          Text('Sign in to your resident account',
                              style: TextStyle(
                                  fontSize: 13, color: AppColors.slate400)),
                          const SizedBox(height: 22),
                          _field(
                            label: 'Email Address',
                            controller: _email,
                            hint: 'Enter your email address',
                            icon: Icons.mail_outline,
                            keyboardType: TextInputType.emailAddress,
                          ),
                          const SizedBox(height: 16),
                          _field(
                            label: 'Password',
                            controller: _password,
                            hint: 'Enter your password',
                            icon: Icons.lock_outline,
                            obscure: _obscure,
                            suffix: IconButton(
                              icon: Icon(
                                  _obscure
                                      ? Icons.visibility_off
                                      : Icons.visibility,
                                  size: 20,
                                  color: AppColors.slate400),
                              onPressed: () =>
                                  setState(() => _obscure = !_obscure),
                            ),
                          ),
                          Row(
                            mainAxisAlignment:
                                MainAxisAlignment.spaceBetween,
                            children: [
                              Flexible(
                                child: InkWell(
                                  onTap: () => setState(
                                      () => _remember = !_remember),
                                  child: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Checkbox(
                                        value: _remember,
                                        visualDensity: VisualDensity.compact,
                                        onChanged: (v) => setState(
                                            () => _remember = v ?? true),
                                      ),
                                      Flexible(
                                        child: Text('Remember me for 7 days',
                                            style: TextStyle(
                                                fontSize: 12,
                                                color: AppColors.slate500)),
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                              TextButton(
                                onPressed: () => Navigator.of(context).push(
                                  MaterialPageRoute(
                                      builder: (_) =>
                                          const ForgotPasswordScreen()),
                                ),
                                child: const Text('Forgot password?'),
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          PrimaryButton(
                            label: 'Sign In',
                            icon: Icons.login,
                            loading: _loading,
                            onPressed: _login,
                          ),
                          const SizedBox(height: 20),
                          const Divider(),
                          const SizedBox(height: 12),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.shield_outlined,
                                  size: 20, color: AppColors.primary),
                              const SizedBox(width: 8),
                              Text('Secure Resident Login System',
                                  style: TextStyle(
                                      fontSize: 13,
                                      color: AppColors.slate400,
                                      fontWeight: FontWeight.w500)),
                            ],
                          ),
                          const SizedBox(height: 10),
                          _requestAccessLink(),
                          const SizedBox(height: 8),
                          _setPasswordLink(),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    _trackCard(),
                    const SizedBox(height: 16),
                    Text('© ${DateTime.now().year} Barangay Biñang 2nd · Bocaue, Bulacan',
                        style: TextStyle(
                            fontSize: 11, color: AppColors.slate400)),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _requestAccessLink() {
    return Wrap(
      alignment: WrapAlignment.center,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text("Don't have an account yet? ",
            style: TextStyle(fontSize: 12, color: AppColors.slate400)),
        GestureDetector(
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const RequestAccessScreen()),
          ),
          child: Text('Request Access',
              style: TextStyle(
                  fontSize: 12,
                  color: AppColors.primary,
                  fontWeight: FontWeight.w600)),
        ),
      ],
    );
  }

  Widget _setPasswordLink() {
    return Wrap(
      alignment: WrapAlignment.center,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        Text('Approved and have an access token? ',
            style: TextStyle(fontSize: 12, color: AppColors.slate400)),
        GestureDetector(
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const SetPasswordScreen()),
          ),
          child: Text('Set Password',
              style: TextStyle(
                  fontSize: 12,
                  color: AppColors.primary,
                  fontWeight: FontWeight.w600)),
        ),
      ],
    );
  }

  Widget _trackCard() {
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.manage_search, size: 22, color: AppColors.primary),
              const SizedBox(width: 8),
              Text('TRACK REQUEST STATUS',
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: AppColors.heading2,
                      letterSpacing: 1)),
            ],
          ),
          const SizedBox(height: 4),
          Text('Enter your email to check the status of your submitted request.',
              style: TextStyle(fontSize: 12, color: AppColors.slate400)),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _trackEmail,
                  keyboardType: TextInputType.emailAddress,
                  decoration: AppTheme.field('Enter your email address',
                      icon: Icons.mail_outline),
                  onSubmitted: (_) => _track(),
                ),
              ),
              const SizedBox(width: 8),
              SizedBox(
                height: 48,
                child: GestureDetector(
                  onTap: _tracking ? null : _track,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 18),
                    alignment: Alignment.center,
                    decoration: AppTheme.primaryButton,
                    child: _tracking
                        ? const SizedBox(
                            height: 18,
                            width: 18,
                            child: CircularProgressIndicator(
                                strokeWidth: 2, color: Colors.white))
                        : const Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text('Check',
                                  style: TextStyle(
                                      color: Colors.white,
                                      fontWeight: FontWeight.w700,
                                      fontSize: 13)),
                              SizedBox(width: 4),
                              Icon(Icons.search,
                                  color: Colors.white, size: 16),
                            ],
                          ),
                  ),
                ),
              ),
            ],
          ),
          if (_trackError != null) ...[
            const SizedBox(height: 10),
            _infoBox(_trackError!, isError: true),
          ],
          if (_tracked != null) ...[
            const SizedBox(height: 12),
            _statusResult(_tracked!),
          ],
        ],
      ),
    );
  }

  Widget _statusResult(AccessRequest r) {
    final color = _statusColor(r.status);
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFFF8FAFC),
        border: Border.all(color: const Color(0xFFE2E8F0)),
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(
                child: Text(r.fullName,
                    style: const TextStyle(
                        fontWeight: FontWeight.w600,
                        color: AppColors.slate800)),
              ),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
                decoration: BoxDecoration(
                  color: color.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(50),
                ),
                child: Text(r.status.db,
                    style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        color: color)),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(r.status.description,
              style: TextStyle(fontSize: 12, color: AppColors.slate500)),
          if ((r.adminReason ?? '').isNotEmpty) ...[
            const SizedBox(height: 4),
            Text('Admin: ${r.adminReason}',
                style: TextStyle(
                    fontSize: 11,
                    fontStyle: FontStyle.italic,
                    color: AppColors.slate500)),
          ],
        ],
      ),
    );
  }

  Widget _infoBox(String msg, {bool isError = false}) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isError ? const Color(0xFFFEF2F2) : const Color(0xFFF8FAFC),
        border: Border.all(
            color: isError ? const Color(0xFFFCA5A5) : const Color(0xFFE2E8F0)),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(msg,
          textAlign: TextAlign.center,
          style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: isError ? const Color(0xFF991B1B) : AppColors.slate500)),
    );
  }

  Color _statusColor(AccessStatus s) {
    switch (s) {
      case AccessStatus.approved:
      case AccessStatus.matched:
        return AppColors.success;
      case AccessStatus.pending:
      case AccessStatus.forProfiling:
      case AccessStatus.forCorrection:
        return const Color(0xFF854D0E);
      case AccessStatus.disapproved:
      case AccessStatus.rejected:
        return const Color(0xFF991B1B);
    }
  }

  Widget _field({
    required String label,
    required TextEditingController controller,
    required String hint,
    required IconData icon,
    bool obscure = false,
    Widget? suffix,
    TextInputType? keyboardType,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        FieldLabel(label),
        TextField(
          controller: controller,
          obscureText: obscure,
          keyboardType: keyboardType,
          decoration: AppTheme.field(hint, icon: icon, suffix: suffix),
        ),
      ],
    );
  }
}
