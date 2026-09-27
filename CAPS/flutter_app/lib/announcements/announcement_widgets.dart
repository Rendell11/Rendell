import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../theme/app_theme.dart';
import 'announcement_api.dart';

/// Colours per category (same idea as the admin announcement badges).
class AnnouncementStyle {
  static Color category(String c) {
    switch (c) {
      case 'Emergency Notice':
        return const Color(0xFFEF4444);
      case 'Health Advisory':
      case 'Health':
        return const Color(0xFF16A34A);
      case 'Community Event':
        return const Color(0xFF8B5CF6);
      case 'General':
        return const Color(0xFF0EA5E9);
      default:
        return const Color(0xFFF59E0B);
    }
  }

  static IconData icon(String c) {
    switch (c) {
      case 'Emergency Notice':
        return Icons.warning_amber_rounded;
      case 'Health Advisory':
      case 'Health':
        return Icons.health_and_safety_outlined;
      case 'Community Event':
        return Icons.event_outlined;
      default:
        return Icons.campaign_outlined;
    }
  }

  static String date(DateTime? d) =>
      d == null ? '—' : '${tr.monthsShort[d.month - 1]} ${d.day}, ${d.year}';

  /// "08:00:00" → "8:00 AM"
  static String time(String? t) {
    if (t == null) return '';
    final p = t.split(':');
    final h = int.tryParse(p[0]) ?? 0;
    final m = p.length > 1 ? p[1] : '00';
    return '${h % 12 == 0 ? 12 : h % 12}:$m ${h < 12 ? 'AM' : 'PM'}';
  }

  /// "Sep 27 – Sep 30, 2026 · 8:00 AM – 5:00 PM" (only the parts that exist).
  static String schedule(Announcement a) {
    final parts = <String>[];
    if (a.dateStart != null || a.dateEnd != null) {
      final s = date(a.dateStart ?? a.dateEnd);
      final e = date(a.dateEnd);
      parts.add(a.dateEnd == null || s == e ? s : '$s – $e');
    }
    if (a.timeStart != null) {
      parts.add(a.timeEnd == null
          ? time(a.timeStart)
          : '${time(a.timeStart)} – ${time(a.timeEnd)}');
    }
    return parts.join(' · ');
  }
}

class AnnouncementPill extends StatelessWidget {
  const AnnouncementPill(this.label, this.color, {super.key, this.icon});

  final String label;
  final Color color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
        decoration: BoxDecoration(
          color: color.withValues(alpha: .13),
          borderRadius: BorderRadius.circular(50),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[
            Icon(icon, size: 12, color: color),
            const SizedBox(width: 4),
          ],
          Flexible(
            child: Text(label.toUpperCase(),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 9.5,
                    fontWeight: FontWeight.w900,
                    letterSpacing: .6,
                    color: color)),
          ),
        ]),
      );
}

/// Network image from the barangay server (works on Flutter web too).
class AnnouncementImage extends StatelessWidget {
  const AnnouncementImage(this.file,
      {super.key, this.height, this.fit = BoxFit.cover});

  final AnnouncementFile file;
  final double? height;
  final BoxFit fit;

  @override
  Widget build(BuildContext context) => Image.network(
        file.fullUrl,
        height: height,
        width: double.infinity,
        fit: fit,
        webHtmlElementStrategy: WebHtmlElementStrategy.fallback,
        errorBuilder: (_, __, ___) => Container(
          height: height ?? 160,
          color: AppColors.surfaceAlt,
          alignment: Alignment.center,
          child: Icon(Icons.broken_image_outlined, color: AppColors.slate400),
        ),
      );
}
