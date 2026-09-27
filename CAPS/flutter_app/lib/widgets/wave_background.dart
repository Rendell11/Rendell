import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// The three animated blue "wave" blobs pinned to the bottom of every SOE
/// portal page, reproduced with a looping [AnimationController].
class WaveBackground extends StatefulWidget {
  const WaveBackground({super.key, required this.child});

  final Widget child;

  @override
  State<WaveBackground> createState() => _WaveBackgroundState();
}

class _WaveBackgroundState extends State<WaveBackground>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(vsync: this, duration: const Duration(seconds: 11))
      ..repeat();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [AppColors.bgTop, AppColors.bgBottom],
        ),
      ),
      child: Stack(
        children: [
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            height: 210,
            child: AnimatedBuilder(
              animation: _c,
              builder: (_, __) => CustomPaint(painter: _WavePainter(_c.value)),
            ),
          ),
          widget.child,
        ],
      ),
    );
  }
}

class _WavePainter extends CustomPainter {
  _WavePainter(this.t);
  final double t;

  @override
  void paint(Canvas canvas, Size size) {
    // Each blob: color, base bottom offset, amplitude, phase, opacity.
    _blob(canvas, size, AppColors.primary, 60, 18, 0.0, 0.80, 220);
    _blob(canvas, size, AppColors.primaryDark, 40, 22, 0.5, 0.90, 190);
    _blob(canvas, size, AppColors.navy, 25, 14, 0.25, 1.0, 150);
  }

  void _blob(Canvas c, Size s, Color color, double bottom, double amp,
      double phase, double opacity, double h) {
    final dx = math.sin((t + phase) * 2 * math.pi) * amp;
    final dy = math.cos((t + phase) * 2 * math.pi) * (amp * 0.3);
    final rect = Rect.fromLTWH(
      -s.width * 0.10 + dx,
      s.height - h + bottom + dy,
      s.width * 1.20,
      h * 2,
    );
    c.drawOval(rect, Paint()..color = color.withOpacity(opacity));
  }

  @override
  bool shouldRepaint(covariant _WavePainter old) => old.t != t;
}
