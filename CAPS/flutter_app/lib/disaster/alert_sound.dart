import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../settings/app_settings.dart';

/// Alert sound per severity (assets/sounds/), and which alerts this device
/// has already announced so each one sounds only once.
///
///   Low      → soft chime
///   Medium   → two double beeps
///   High     → urgent triple beeps
///   Critical → siren
///
/// Off when Settings → Notifications → "Alert sound" is turned off.
class AlertSound {
  AlertSound._();
  static final instance = AlertSound._();

  // Created on first use (never in widget tests that only check the asset).
  late final AudioPlayer _player = AudioPlayer();

  /// Tests: receives the asset instead of playing it.
  @visibleForTesting
  static void Function(String asset)? debugOnPlay;
  final _store = const FlutterSecureStorage();
  static const _kAnnounced = 'alerts_announced';

  Set<int>? _announced;

  static String asset(String? severity) => switch (severity) {
        'Critical' => 'sounds/alert_critical.wav',
        'High' => 'sounds/alert_high.wav',
        'Medium' => 'sounds/alert_medium.wav',
        _ => 'sounds/alert_low.wav',
      };

  /// Play the sound for [severity] (respects the setting unless [force]).
  Future<void> play(String? severity, {bool force = false}) async {
    if (!force && !AppSettings.instance.alertSound) return;
    if (debugOnPlay != null) return debugOnPlay!(asset(severity));
    try {
      await _player.stop();
      await _player.setReleaseMode(ReleaseMode.stop);
      await _player.play(AssetSource(asset(severity)), volume: 1.0);
    } catch (e) {
      // e.g. the browser blocks audio before the first tap — never crash.
      debugPrint('[alert sound] $e');
    }
  }

  Future<void> stop() async {
    if (debugOnPlay != null) return;
    try {
      await _player.stop();
    } catch (_) {}
  }

  Future<Set<int>> _load() async {
    if (_announced != null) return _announced!;
    try {
      final raw = await _store.read(key: _kAnnounced) ?? '';
      _announced = raw.split(',').map(int.tryParse).whereType<int>().toSet();
    } catch (_) {
      _announced = {};
    }
    return _announced!;
  }

  /// The alert ids in [ids] that this device has not announced yet; they are
  /// remembered as announced from now on.
  Future<List<int>> takeNew(Iterable<int> ids) async {
    final seen = await _load();
    final fresh = ids.where((id) => !seen.contains(id)).toList();
    if (fresh.isNotEmpty) {
      seen.addAll(fresh);
      // Keep the list short (newest ids are the largest).
      final keep = (seen.toList()..sort()).reversed.take(50).toList();
      _announced = keep.toSet();
      try {
        await _store.write(key: _kAnnounced, value: keep.join(','));
      } catch (_) {}
    }
    return fresh;
  }
}
