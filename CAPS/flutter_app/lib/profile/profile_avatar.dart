import 'package:flutter/material.dart';

import '../config/api_config.dart';
import '../theme/app_theme.dart';

/// Round profile picture; falls back to the resident's initials when there is
/// no photo (or it fails to load). [photoUrl] is relative to the API base URL.
class ProfileAvatar extends StatelessWidget {
  const ProfileAvatar({
    super.key,
    required this.initials,
    this.photoUrl,
    this.size = 40,
    this.onGradient = false,
  });

  final String initials;
  final String? photoUrl;
  final double size;

  /// Translucent white style for use on the accent gradient headers.
  final bool onGradient;

  @override
  Widget build(BuildContext context) {
    final fallback = Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: onGradient
            ? Colors.white.withValues(alpha: .20)
            : AppColors.primary,
        shape: BoxShape.circle,
        border: onGradient
            ? Border.all(color: Colors.white.withValues(alpha: .35), width: 2)
            : null,
      ),
      child: Text(initials,
          style: TextStyle(
              color: Colors.white,
              fontSize: size * .36,
              fontWeight: FontWeight.w900)),
    );
    if ((photoUrl ?? '').isEmpty) return fallback;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: onGradient
            ? Border.all(color: Colors.white.withValues(alpha: .6), width: 2)
            : null,
      ),
      child: ClipOval(
        child: Image.network(
          '${ApiConfig.baseUrl}/$photoUrl',
          // Flutter web: XAMPP serves the file without CORS headers, so fall
          // back to a plain <img> element instead of failing.
          webHtmlElementStrategy: WebHtmlElementStrategy.fallback,
          width: size,
          height: size,
          fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => fallback,
        ),
      ),
    );
  }
}
