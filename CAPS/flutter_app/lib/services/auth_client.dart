import 'package:http/http.dart' as http;

import '../config/api_config.dart';

/// HTTP client used by every module API. It watches for 401 answers: the
/// backend sends one when the login token (X-Auth-Token) is missing,
/// expired or revoked, and the app then goes back to the login screen
/// ([ApiConfig.onUnauthorized], set up in main.dart).
class AuthClient extends http.BaseClient {
  AuthClient([http.Client? inner]) : _inner = inner ?? http.Client();

  final http.Client _inner;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) async {
    final res = await _inner.send(request);
    ApiConfig.checkStatus(res.statusCode);
    return res;
  }

  /// Send a one-off request (e.g. a MultipartRequest) with the same check.
  static Future<http.StreamedResponse> sendOnce(
      http.BaseRequest request) async {
    final client = AuthClient();
    try {
      return await client.send(request);
    } finally {
      client.close();
    }
  }

  @override
  void close() => _inner.close();
}
