import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../theme/app_theme.dart';

/// Small UI pieces shared by the complaint screens. Colours follow the admin
/// complaint_rep.php badges (Pending = orange, Ongoing = blue, Resolved = green;
/// Low = green, Medium = amber, High = red).

class ComplaintStyle {
  static const pending = Color(0xFFF59E0B);
  static const ongoing = Color(0xFF1D63DA);
  static const resolved = Color(0xFF16A34A);

  static Color status(String s) {
    switch (s) {
      case 'Resolved':
        return resolved;
      case 'Ongoing':
        return ongoing;
      default:
        return pending;
    }
  }

  static IconData statusIcon(String s) {
    switch (s) {
      case 'Resolved':
        return Icons.check_circle;
      case 'Ongoing':
        return Icons.autorenew;
      default:
        return Icons.hourglass_top;
    }
  }

  static Color priority(String p) {
    switch (p) {
      case 'Low':
        return const Color(0xFF16A34A);
      case 'High (Urgent)':
        return const Color(0xFFEF4444);
      default:
        return const Color(0xFFD97706);
    }
  }

  /// "Sep 27, 2026" in the app language (no intl dependency needed).
  static String date(DateTime? d) =>
      d == null ? '—' : '${tr.monthsShort[d.month - 1]} ${d.day}, ${d.year}';

  /// "Sep 27, 2026 · 2:05 PM"
  static String dateTime(DateTime? d) {
    if (d == null) return '—';
    final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
    final m = d.minute.toString().padLeft(2, '0');
    return '${date(d)} · $h:$m ${d.hour < 12 ? 'AM' : 'PM'}';
  }
}

/// Coloured pill, e.g. status or priority.
class ComplaintPill extends StatelessWidget {
  const ComplaintPill(this.label, this.color, {super.key, this.icon});

  final String label;
  final Color color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .12),
        borderRadius: BorderRadius.circular(50),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 12, color: color),
            const SizedBox(width: 4),
          ],
          Text(label.toUpperCase(),
              style: TextStyle(
                  fontSize: 9.5,
                  fontWeight: FontWeight.w900,
                  letterSpacing: .6,
                  color: color)),
        ],
      ),
    );
  }
}

/// White rounded card used across the resident dashboard.
class ComplaintCard extends StatelessWidget {
  const ComplaintCard({super.key, required this.child, this.padding});

  final Widget child;
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: padding ?? const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(
            color: AppColors.isDark ? AppColors.border : AppColors.bgBottom),
        boxShadow: [
          BoxShadow(
              color: AppColors.primary.withValues(alpha: .06),
              blurRadius: 18,
              offset: const Offset(0, 6)),
        ],
      ),
      child: child,
    );
  }
}

/// Uppercase section label, same as the dashboard's.
class ComplaintSectionLabel extends StatelessWidget {
  const ComplaintSectionLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(left: 4, bottom: 8),
        child: Text(text.toUpperCase(),
            style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w900,
                letterSpacing: .8,
                color: AppColors.heading2)),
      );
}

/// Dark app bar used by the resident module screens (matches dashboard/chat).
PreferredSizeWidget complaintAppBar(String title, String subtitle,
    {List<Widget>? actions}) {
  return AppBar(
    backgroundColor: AppColors.appBar,
    foregroundColor: Colors.white,
    elevation: 0,
    title: Row(
      children: [
        Container(
          width: 34,
          height: 34,
          decoration: BoxDecoration(
            gradient: LinearGradient(
                colors: [AppColors.primary, AppColors.primaryDark]),
            borderRadius: BorderRadius.circular(10),
          ),
          child: const Icon(Icons.report_problem_outlined,
              color: Colors.white, size: 18),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      fontSize: 15, fontWeight: FontWeight.w800)),
              Text(subtitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      fontSize: 11,
                      color: Colors.white70,
                      fontWeight: FontWeight.w500)),
            ],
          ),
        ),
      ],
    ),
    actions: actions,
  );
}
