import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../services/session_service.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import '../widgets/pin_pad.dart';

/// Settings → Change PIN (or set one up if the resident skipped it).
/// Steps: current PIN (if any) → new PIN → confirm. Pops `true` when saved.
class ChangePinScreen extends StatefulWidget {
  const ChangePinScreen({super.key, required this.hasPin});

  final bool hasPin;

  @override
  State<ChangePinScreen> createState() => _ChangePinScreenState();
}

enum _Step { current, fresh, confirm }

class _ChangePinScreenState extends State<ChangePinScreen> {
  final _session = SessionService();
  final _padKey = GlobalKey<PinPadState>();

  late _Step _step = widget.hasPin ? _Step.current : _Step.fresh;
  String? _newPin;
  String? _error;

  Future<void> _onPin(String pin) async {
    switch (_step) {
      case _Step.current:
        if (await _session.verifyPin(pin)) {
          setState(() {
            _step = _Step.fresh;
            _error = null;
          });
        } else {
          setState(() => _error = tr.wrongPin);
        }
        break;
      case _Step.fresh:
        setState(() {
          _newPin = pin;
          _step = _Step.confirm;
          _error = null;
        });
        break;
      case _Step.confirm:
        if (pin != _newPin) {
          setState(() {
            _error = tr.pinMismatch;
            _newPin = null;
            _step = _Step.fresh;
          });
        } else {
          await _session.setPin(pin);
          if (mounted) Navigator.of(context).pop(true);
          return;
        }
        break;
    }
    _padKey.currentState?.reset();
  }

  @override
  Widget build(BuildContext context) {
    final (title, sub) = switch (_step) {
      _Step.current => (tr.enterCurrentPin, tr.enterCurrentPinSub),
      _Step.fresh => (tr.createPin, tr.createPinSub),
      _Step.confirm => (tr.confirmPin, tr.confirmPinSub),
    };
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: AppBar(
        backgroundColor: AppColors.appBar,
        foregroundColor: Colors.white,
        title: Text(widget.hasPin ? tr.changePin : tr.setUpPin,
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: GlassCard(
              padding: const EdgeInsets.all(28),
              child: Column(
                children: [
                  Icon(Icons.pin_outlined, size: 40, color: AppColors.primary),
                  const SizedBox(height: 12),
                  Text(title.toUpperCase(),
                      style: TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.w800,
                          color: AppColors.heading2)),
                  const SizedBox(height: 4),
                  Text(sub,
                      textAlign: TextAlign.center,
                      style:
                          TextStyle(fontSize: 13, color: AppColors.slate400)),
                  const SizedBox(height: 24),
                  PinPad(key: _padKey, onCompleted: _onPin, errorText: _error),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
