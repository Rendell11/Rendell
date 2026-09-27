import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart' show ApiResult;

/// HTTP client for the Settings module:
///   * `preferences.php`    — the resident's app preferences (user_preferences)
///   * `change_password.php` — change the portal password
class SettingsApi {
  SettingsApi({http.Client? client, String? baseUrl})
      : _client = client ?? http.Client(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Uri _uri(String path, [Map<String, String>? q]) =>
      Uri.parse('$_baseUrl/$path').replace(queryParameters: q);

  /// Saved preferences as key → value, or null when offline/unavailable.
  Future<Map<String, String>?> fetch(int residentId) async {
    try {
      final res = await _client
          .get(_uri('preferences.php', {'resident_id': '$residentId'}),
              headers: ApiConfig.headers)
          .timeout(const Duration(seconds: 8));
      final body = _decode(res);
      final data = body['data'];
      if (res.statusCode == 200 && body['success'] == true && data is Map) {
        return data.map((k, v) => MapEntry(k.toString(), v.toString()));
      }
    } catch (_) {}
    return null;
  }

  /// Save preferences (best-effort; the device copy is the fallback).
  Future<bool> save(int residentId, Map<String, String> prefs) async {
    try {
      final res = await _client.post(_uri('preferences.php'),
          headers: ApiConfig.headers,
          body: {
            'resident_id': '$residentId',
            ...prefs
          }).timeout(const Duration(seconds: 8));
      return _decode(res)['success'] == true;
    } catch (_) {
      return false;
    }
  }

  Future<ApiResult<void>> changePassword({
    required int residentId,
    required String currentPassword,
    required String newPassword,
  }) async {
    try {
      final res = await _client.post(
        _uri('change_password.php'),
        headers: ApiConfig.headers,
        body: {
          'resident_id': '$residentId',
          'current_password': currentPassword,
          'new_password': newPassword,
        },
      ).timeout(ApiConfig.timeout);
      final body = _decode(res);
      final msg = body['message']?.toString() ?? '';
      if (res.statusCode == 200 && body['success'] == true) {
        return ApiResult.success(null,
            message: msg.isNotEmpty ? msg : tr.passwordChanged);
      }
      return ApiResult.failure(msg.isNotEmpty ? msg : tr.passwordChangeFailed);
    } on TimeoutException {
      return ApiResult.failure(tr.errTimeout);
    } on http.ClientException {
      return ApiResult.failure(tr.errNoConnection);
    } catch (e) {
      return ApiResult.failure(tr.errGeneric('$e'));
    }
  }

  void dispose() => _client.close();

  Map<String, dynamic> _decode(http.Response res) {
    try {
      final d = jsonDecode(res.body);
      return d is Map<String, dynamic> ? d : {};
    } catch (_) {
      return {};
    }
  }
}
