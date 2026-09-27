/// Central place for the backend base URL.
///
/// Point [baseUrl] at wherever the PHP API in `backend_api/` is served.
///
/// The API lives at CAPS/user/backend/ under your XAMPP htdocs, so the path is
/// `/CAPS/user/backend`. Common host values while developing with XAMPP:
///   * Android emulator ....... http://10.0.2.2/CAPS/user/backend
///   * iOS simulator .......... http://127.0.0.1/CAPS/user/backend
///   * Real device (same wifi)  http://192.168.x.x/CAPS/user/backend
///   * Chrome / web ........... http://localhost/CAPS/user/backend
///
/// You can also override it at build/run time without editing code:
///   flutter run --dart-define=API_BASE_URL=http://192.168.1.10/CAPS/user/backend
class ApiConfig {
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2/CAPS/user/backend',
  );

  /// How long to wait for the server before giving up.
  static const Duration timeout = Duration(seconds: 20);

  /// App language ("en" / "fil"), kept in sync by AppSettings. Sent on every
  /// request so the backend answers in the same language as the app.
  static String lang = 'fil';

  static Map<String, String> get headers => {'X-App-Lang': lang};
}
