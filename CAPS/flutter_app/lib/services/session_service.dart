import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/resident.dart';

/// Persists a "stay signed in" session (bank-app style) in the platform's
/// secure store (Android Keystore / iOS Keychain) and manages the PIN.
///
/// - After a full login the resident is saved with a timestamp.
/// - The session is valid for [sessionDays] (default 7); after that a full
///   login is required again.
/// - Quick unlock uses biometrics and/or a PIN — the resident does not retype
///   their email/password each time.
class SessionService {
  SessionService({FlutterSecureStorage? storage})
      : _s = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _s;

  static const int sessionDays = 7;

  static const _kResident = 'session_resident';
  static const _kLoginAt = 'session_login_at';
  static const _kPinHash = 'session_pin_hash';
  static const _kPinSalt = 'session_pin_salt';
  static const _kBiometric = 'session_biometric_enabled';

  // ── Session ────────────────────────────────────────────────────────────

  /// Save a fresh session after a successful full login.
  Future<void> startSession(Resident resident) async {
    await _s.write(key: _kResident, value: jsonEncode(resident.toJson()));
    await _s.write(
        key: _kLoginAt, value: DateTime.now().toIso8601String());
  }

  /// The stored resident, or null when there is no session.
  Future<Resident?> currentResident() async {
    final raw = await _s.read(key: _kResident);
    if (raw == null) return null;
    try {
      return Resident.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  /// True when a session exists AND is within the [sessionDays] window.
  Future<bool> hasValidSession() async {
    final resident = await _s.read(key: _kResident);
    final at = await _s.read(key: _kLoginAt);
    if (resident == null || at == null) return false;
    final loginAt = DateTime.tryParse(at);
    if (loginAt == null) return false;
    final expired =
        DateTime.now().difference(loginAt) > const Duration(days: sessionDays);
    if (expired) {
      await clear();
      return false;
    }
    return true;
  }

  /// When the saved "stay signed in" session runs out, or null if none.
  Future<DateTime?> sessionExpiry() async {
    final at = DateTime.tryParse(await _s.read(key: _kLoginAt) ?? '');
    return at?.add(const Duration(days: sessionDays));
  }

  /// Full sign-out — wipes the session and the PIN/biometric setup.
  Future<void> clear() async {
    await _s.delete(key: _kResident);
    await _s.delete(key: _kLoginAt);
    await _s.delete(key: _kPinHash);
    await _s.delete(key: _kPinSalt);
    await _s.delete(key: _kBiometric);
  }

  // ── PIN ─────────────────────────────────────────────────────────────────

  Future<bool> hasPin() async => (await _s.read(key: _kPinHash)) != null;

  Future<void> setPin(String pin) async {
    final salt = DateTime.now().microsecondsSinceEpoch.toString();
    await _s.write(key: _kPinSalt, value: salt);
    await _s.write(key: _kPinHash, value: _hash(pin, salt));
  }

  Future<bool> verifyPin(String pin) async {
    final salt = await _s.read(key: _kPinSalt);
    final hash = await _s.read(key: _kPinHash);
    if (salt == null || hash == null) return false;
    return _hash(pin, salt) == hash;
  }

  String _hash(String pin, String salt) =>
      sha256.convert(utf8.encode('$salt:$pin')).toString();

  // ── Biometrics preference ────────────────────────────────────────────────

  Future<bool> biometricEnabled() async =>
      (await _s.read(key: _kBiometric)) == 'true';

  Future<void> setBiometricEnabled(bool enabled) async =>
      _s.write(key: _kBiometric, value: enabled ? 'true' : 'false');
}
