import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../settings/app_settings.dart';
import '../theme/app_theme.dart';

/// The EN | FIL pill (from SOE request_access.php). Changes the app language
/// everywhere — the same setting as Settings → Language — so residents can
/// pick it before they log in.
class LanguageToggle extends StatelessWidget {
  const LanguageToggle({super.key});

  @override
  Widget build(BuildContext context) {
    final settings = AppSettings.instance;
    Widget seg(String label, AppLanguage lang) {
      final active = settings.language == lang;
      return GestureDetector(
        onTap: () => settings.setLanguage(lang),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
          decoration: BoxDecoration(
            gradient: active
                ? LinearGradient(
                    colors: [AppColors.primary, AppColors.primaryDark])
                : null,
          ),
          child: Text(label,
              style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: active ? Colors.white : AppColors.slate500)),
        ),
      );
    }

    return Semantics(
      label: tr.language,
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.muted,
          border: Border.all(color: AppColors.slate200),
          borderRadius: BorderRadius.circular(50),
        ),
        clipBehavior: Clip.antiAlias,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            seg('EN', AppLanguage.en),
            seg('FIL', AppLanguage.fil),
          ],
        ),
      ),
    );
  }
}
