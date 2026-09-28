import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import '../notifications/notifications_api.dart';

/// Push notifications (Firebase Cloud Messaging) — they pop up even when the
/// app is closed.
///
/// ANDROID (the resident app): put the file from the Firebase console at
/// `android/app/google-services.json` and build. Nothing else to pass.
///
/// WEB (optional): pass the Firebase values at build/run time instead:
///   --dart-define=FIREBASE_API_KEY=... --dart-define=FIREBASE_APP_ID=...
///   --dart-define=FIREBASE_SENDER_ID=... --dart-define=FIREBASE_PROJECT_ID=...
///   --dart-define=FIREBASE_VAPID_KEY=...
///
/// Without either, push is simply off and the rest of the app works.
/// The server side is user/backend/push_lib.php (+ the service-account key).
///
/// Android notification channels (created here) give each kind of push its
/// own sound — the server picks the channel:
///   barangay_updates → phone's default sound (requests, complaints, news)
///   alert_low / alert_medium / alert_high / alert_critical → the same sounds
///     as in the app (android/app/src/main/res/raw); Critical also rings in
///     silent mode (alarm)
///   alert_silent → disaster alert without sound (Settings → Alert sound off)
class PushService {
  PushService._();
  static final instance = PushService._();

  static const _apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const _appId = String.fromEnvironment('FIREBASE_APP_ID');
  static const _senderId = String.fromEnvironment('FIREBASE_SENDER_ID');
  static const _projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
  static const _vapidKey = String.fromEnvironment('FIREBASE_VAPID_KEY');

  static bool get _hasDartDefines =>
      _apiKey.isNotEmpty &&
      _appId.isNotEmpty &&
      _senderId.isNotEmpty &&
      _projectId.isNotEmpty;

  static bool get _isAndroid =>
      !kIsWeb && defaultTargetPlatform == TargetPlatform.android;

  bool _started = false;
  bool _channelsReady = false;

  /// Called for a message that arrives while the app is open.
  void Function(RemoteMessage message)? onForeground;

  /// Called when the resident taps a push notification.
  void Function(RemoteMessage message)? onOpened;

  /// Ask for permission, get this device's token and send it to the server.
  /// Safe to call after every login / unlock; does nothing when not set up.
  Future<void> register() async {
    try {
      await _createAndroidChannels();
      if (!await _initFirebase()) return;
      final fm = FirebaseMessaging.instance;
      final perm = await fm.requestPermission(); // Android 13+ / web / iOS
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

  /// Android: from google-services.json; web: from the --dart-define values.
  Future<bool> _initFirebase() async {
    if (Firebase.apps.isNotEmpty) return true;
    try {
      if (_hasDartDefines) {
        await Firebase.initializeApp(
          options: const FirebaseOptions(
            apiKey: _apiKey,
            appId: _appId,
            messagingSenderId: _senderId,
            projectId: _projectId,
          ),
        );
        return true;
      }
      if (_isAndroid) {
        await Firebase
            .initializeApp(); // needs android/app/google-services.json
        return true;
      }
    } catch (e) {
      debugPrint('[push] Firebase is not set up: $e');
    }
    return false;
  }

  Future<void> _createAndroidChannels() async {
    if (!_isAndroid || _channelsReady) return;
    _channelsReady = true;
    final android = FlutterLocalNotificationsPlugin()
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>();
    if (android == null) return;
    const channels = [
      AndroidNotificationChannel(
        'barangay_updates',
        'Barangay updates',
        description: 'Document requests, complaints, blotter, announcements',
        importance: Importance.high,
      ),
      AndroidNotificationChannel(
        'alert_low',
        'Disaster alert · Low',
        importance: Importance.high,
        sound: RawResourceAndroidNotificationSound('alert_low'),
      ),
      AndroidNotificationChannel(
        'alert_medium',
        'Disaster alert · Medium',
        importance: Importance.high,
        sound: RawResourceAndroidNotificationSound('alert_medium'),
      ),
      AndroidNotificationChannel(
        'alert_high',
        'Disaster alert · High',
        importance: Importance.max,
        sound: RawResourceAndroidNotificationSound('alert_high'),
      ),
      AndroidNotificationChannel(
        'alert_critical',
        'Disaster alert · Critical',
        description: 'Siren — plays even in silent mode',
        importance: Importance.max,
        sound: RawResourceAndroidNotificationSound('alert_critical'),
        audioAttributesUsage: AudioAttributesUsage.alarm,
        bypassDnd: true,
      ),
      AndroidNotificationChannel(
        'alert_silent',
        'Disaster alert · No sound',
        description: 'Used when Settings → Alert sound is off',
        importance: Importance.high,
        playSound: false,
      ),
    ];
    for (final c in channels) {
      await android.createNotificationChannel(c);
    }
  }

  Future<void> _send(String? token) async {
    final api = NotificationsApi();
    await api.registerDevice(token);
    api.dispose();
  }
}
