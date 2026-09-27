import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// A 6-digit PIN entry: dots showing progress + a numeric keypad.
/// Calls [onCompleted] once [length] digits are entered.
class PinPad extends StatefulWidget {
  const PinPad({
    super.key,
    required this.onCompleted,
    this.length = 6,
    this.showBiometricButton = false,
    this.onBiometric,
    this.errorText,
  });

  final int length;
  final ValueChanged<String> onCompleted;
  final bool showBiometricButton;
  final VoidCallback? onBiometric;
  final String? errorText;

  @override
  State<PinPad> createState() => PinPadState();
}

class PinPadState extends State<PinPad> {
  String _pin = '';

  /// Clear the entry (e.g. after a wrong PIN).
  void reset() => setState(() => _pin = '');

  void _tap(String d) {
    if (_pin.length >= widget.length) return;
    setState(() => _pin += d);
    if (_pin.length == widget.length) {
      widget.onCompleted(_pin);
    }
  }

  void _backspace() {
    if (_pin.isEmpty) return;
    setState(() => _pin = _pin.substring(0, _pin.length - 1));
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        // dots
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: List.generate(widget.length, (i) {
            final filled = i < _pin.length;
            return Container(
              margin: const EdgeInsets.symmetric(horizontal: 7),
              width: 14,
              height: 14,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: filled ? AppColors.primary : Colors.transparent,
                border: Border.all(
                    color: filled ? AppColors.primary : AppColors.slate200,
                    width: 2),
              ),
            );
          }),
        ),
        if (widget.errorText != null) ...[
          const SizedBox(height: 12),
          Text(widget.errorText!,
              style: const TextStyle(
                  color: AppColors.danger,
                  fontSize: 13,
                  fontWeight: FontWeight.w600)),
        ],
        const SizedBox(height: 28),
        // keypad
        for (final row in const [
          ['1', '2', '3'],
          ['4', '5', '6'],
          ['7', '8', '9'],
        ])
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: row.map(_digit).toList(),
          ),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            widget.showBiometricButton
                ? _key(
                    child: Icon(Icons.fingerprint,
                        size: 30, color: AppColors.primary),
                    onTap: widget.onBiometric)
                : const SizedBox(width: 74),
            _digit('0'),
            _key(
                child: Icon(Icons.backspace_outlined,
                    color: AppColors.slate500),
                onTap: _backspace),
          ],
        ),
      ],
    );
  }

  Widget _digit(String d) => _key(
        child: Text(d,
            style: TextStyle(
                fontSize: 26,
                fontWeight: FontWeight.w600,
                color: AppColors.slate800)),
        onTap: () => _tap(d),
      );

  Widget _key({required Widget child, VoidCallback? onTap}) {
    return Padding(
      padding: const EdgeInsets.all(8),
      child: Material(
        color: AppColors.surface,
        shape: const CircleBorder(),
        elevation: 1,
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: onTap,
          child: SizedBox(width: 58, height: 58, child: Center(child: child)),
        ),
      ),
    );
  }
}
