import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import '../notifications/notifications_api.dart';

/// Push notifications (Firebase Cloud Messaging).
///
/// OFF until the Firebase project values are passed at build/run time — no
/// google-services.json or gradle change needed:
///
///   flutter run --dart-define=FIREBASE_API_KEY=... \
///     --dart-define=FIREBASE_APP_ID=... \
///     --dart-define=FIREBASE_SENDER_ID=... \
///     --dart-define=FIREBASE_PROJECT_ID=... \
///     --dart-define=FIREBASE_VAPID_KEY=...   (web only)
///
/// The server side is user/backend/push_lib.php (+ the service-account key).
class PushService {
  PushService._();
  static final instance = PushService._();

  static const _apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const _appId = String.fromEnvironment('FIREBASE_APP_ID');
  static const _senderId = String.fromEnvironment('FIREBASE_SENDER_ID');
  static const _projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
  static const _vapidKey = String.fromEnvironment('FIREBASE_VAPID_KEY');

  static bool get configured =>
      _apiKey.isNotEmpty &&
      _appId.isNotEmpty &&
      _senderId.isNotEmpty &&
      _projectId.isNotEmpty;

  bool _started = false;

  /// Called for a message that arrives while the app is open.
  void Function(RemoteMessage message)? onForeground;

  /// Called when the resident taps a push notification.
  void Function(RemoteMessage message)? onOpened;

  /// Ask for permission, get this device's token and send it to the server.
  /// Safe to call after every login / unlock; does nothing when not set up.
  Future<void> register() async {
    if (!configured) return;
    try {
      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp(
          options: const FirebaseOptions(
            apiKey: _apiKey,
            appId: _appId,
            messagingSenderId: _senderId,
            projectId: _projectId,
          ),
        );
      }
      final fm = FirebaseMessaging.instance;
      final perm = await fm.requestPermission();
      if (perm.authorizationStatus == AuthorizationStatus.denied) return;
      final token = await fm.getToken(
          vapidKey: kIsWeb && _vapidKey.isNotEmpty ? _vapidKey : null);
      await _send(token);

      if (!_started) {
        _started = true;
        fm.onTokenRefresh.listen(_send);
        FirebaseMessaging.onMessage.listen((m) => onForeground?.call(m));
        FirebaseMessaging.onMessageOpenedApp.listen((m) => onOpened?.call(m));
        final initial = await fm.getInitialMessage();
        if (initial != null) onOpened?.call(initial);
      }
    } catch (e) {
      debugPrint('[push] $e');
    }
  }

  Future<void> _send(String? token) async {
    final api = NotificationsApi();
    await api.registerDevice(token);
    api.dispose();
  }
}
