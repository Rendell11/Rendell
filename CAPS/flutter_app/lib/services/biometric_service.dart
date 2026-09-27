import 'package:local_auth/local_auth.dart';

/// Thin wrapper over local_auth for fingerprint / face unlock.
class BiometricService {
  final LocalAuthentication _auth = LocalAuthentication();

  /// True when the device has biometrics (or a device credential) available.
  Future<bool> isAvailable() async {
    try {
      final supported = await _auth.isDeviceSupported();
      final canCheck = await _auth.canCheckBiometrics;
      return supported && canCheck;
    } catch (_) {
      return false;
    }
  }

  /// Prompt the OS biometric sheet. Returns true only on a successful match.
  Future<bool> authenticate(
      [String reason = 'Kumpirmahin para buksan ang iyong account']) async {
    try {
      return await _auth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(
          stickyAuth: true,
          biometricOnly: false, // allow device PIN/pattern as fallback too
        ),
      );
    } catch (_) {
      return false;
    }
  }
}
