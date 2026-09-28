import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../theme/app_theme.dart';

/// Password rules — the same as the server (lib.php password_policy_error):
/// 8+ characters, an uppercase and a lowercase letter, a number and a special
/// character, no spaces.
class PasswordPolicy {
  PasswordPolicy._();

  static bool longEnough(String p) => p.length >= 8;
  static bool hasUpper(String p) => RegExp(r'[A-Z]').hasMatch(p);
  static bool hasLower(String p) => RegExp(r'[a-z]').hasMatch(p);
  static bool hasNumber(String p) => RegExp(r'[0-9]').hasMatch(p);
  static bool hasSpecial(String p) => RegExp(r'[^A-Za-z0-9\s]').hasMatch(p);
  static bool noSpaces(String p) => !RegExp(r'\s').hasMatch(p);

  static List<(String, bool)> checks(String p) => [
        (tr.pwRuleLength, longEnough(p)),
        (tr.pwRuleUpper, hasUpper(p)),
        (tr.pwRuleLower, hasLower(p)),
        (tr.pwRuleNumber, hasNumber(p)),
        (tr.pwRuleSpecial, hasSpecial(p)),
      ];

  /// Form validator: null when every rule passes.
  static String? validate(String? v) {
    final p = v ?? '';
    if (p.isEmpty) return tr.required;
    if (!noSpaces(p)) return tr.pwNoSpaces;
    if (p.length > 72) return tr.pwTooLong;
    if (checks(p).any((c) => !c.$2)) return tr.pwWeak;
    return null;
  }

  /// 0–5 rules met.
  static int score(String p) => checks(p).where((c) => c.$2).length;
}

/// Live checklist + strength bar under a new-password field.
class PasswordRules extends StatelessWidget {
  const PasswordRules({super.key, required this.controller});

  final TextEditingController controller;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<TextEditingValue>(
      valueListenable: controller,
      builder: (context, value, _) {
        final p = value.text;
        final score = PasswordPolicy.score(p);
        final (label, color) = switch (score) {
          5 when PasswordPolicy.noSpaces(p) => (tr.pwStrong, AppColors.success),
          >= 4 => (tr.pwMedium, const Color(0xFFF59E0B)),
          _ => (tr.pwWeakLabel, AppColors.danger),
        };
        return Padding(
          padding: const EdgeInsets.only(top: 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (p.isNotEmpty) ...[
                Row(
                  children: [
                    Expanded(
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(4),
                        child: LinearProgressIndicator(
                          value: score / 5,
                          minHeight: 6,
                          color: color,
                          backgroundColor:
                              AppColors.slate400.withValues(alpha: 0.25),
                        ),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Text(label,
                        style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                            color: color)),
                  ],
                ),
                const SizedBox(height: 8),
              ],
              for (final (text, ok) in PasswordPolicy.checks(p))
                Padding(
                  padding: const EdgeInsets.only(bottom: 3),
                  child: Row(
                    children: [
                      Icon(
                          ok
                              ? Icons.check_circle
                              : Icons.radio_button_unchecked,
                          size: 16,
                          color: ok ? AppColors.success : AppColors.slate400),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(text,
                            style: TextStyle(
                                fontSize: 12,
                                color: ok
                                    ? AppColors.success
                                    : AppColors.slate500)),
                      ),
                    ],
                  ),
                ),
              if (p.isNotEmpty && !PasswordPolicy.noSpaces(p))
                Text(tr.pwNoSpaces,
                    style:
                        const TextStyle(fontSize: 12, color: AppColors.danger)),
            ],
          ),
        );
      },
    );
  }
}
