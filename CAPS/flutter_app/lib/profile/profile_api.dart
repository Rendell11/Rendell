import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart' show ApiResult;
import 'profile_model.dart';

/// HTTP client for `user/backend/profile.php` (same envelope as ApiService).
class ProfileApi {
  ProfileApi({http.Client? client, String? baseUrl})
      : _client = client ?? http.Client(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Uri _uri([Map<String, String>? q]) =>
      Uri.parse('$_baseUrl/profile.php').replace(queryParameters: q);

  /// Full URL for a photo path returned by the server.
  String photoUrl(String relative) => '$_baseUrl/$relative';

  Future<ApiResult<ResidentProfile>> get(int residentId) async {
    try {
      final res = await _client
          .get(_uri({'action': 'get', 'resident_id': '$residentId'}),
              headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body) && body['data'] is Map) {
        return ApiResult.success(
            ResidentProfile.fromJson(Map<String, dynamic>.from(body['data'])));
      }
      return ApiResult.failure(_msg(body, tr.profileLoadFailed));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// Upload a new profile picture. Returns the new photo path.
  Future<ApiResult<String>> uploadPhoto(
      int residentId, Uint8List bytes, String filename) async {
    try {
      final req = http.MultipartRequest('POST', _uri())
        ..headers.addAll(ApiConfig.headers)
        ..fields
            .addAll({'action': 'upload_photo', 'resident_id': '$residentId'})
        ..files.add(
            http.MultipartFile.fromBytes('photo', bytes, filename: filename));
      final res = await http.Response.fromStream(
          await req.send().timeout(ApiConfig.timeout));
      final body = _decode(res);
      if (_ok(res, body)) {
        return ApiResult.success(body['data']?['photo_url']?.toString(),
            message: _msg(body, tr.photoUpdated));
      }
      return ApiResult.failure(_msg(body, tr.photoUploadFailed));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  Future<ApiResult<void>> removePhoto(int residentId) async {
    try {
      final res = await _client.post(_uri(), headers: ApiConfig.headers, body: {
        'action': 'remove_photo',
        'resident_id': '$residentId'
      }).timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body)) {
        return ApiResult.success(null, message: _msg(body, tr.photoRemoved));
      }
      return ApiResult.failure(_msg(body, tr.photoUploadFailed));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  void dispose() => _client.close();

  Map<String, dynamic> _decode(http.Response res) {
    if (res.body.isEmpty) return {};
    try {
      final d = jsonDecode(res.body);
      return d is Map<String, dynamic> ? d : {'data': d};
    } catch (_) {
      return {'success': false, 'message': tr.errBadResponse};
    }
  }

  bool _ok(http.Response res, Map<String, dynamic> body) =>
      res.statusCode >= 200 && res.statusCode < 300 && body['success'] == true;

  String _msg(Map<String, dynamic> body, String fallback) =>
      body['message']?.toString().isNotEmpty == true
          ? body['message'].toString()
          : fallback;

  String _friendly(Object e) {
    if (e is TimeoutException) return tr.errTimeout;
    if (e is http.ClientException) return tr.errNoConnection;
    return tr.errGeneric('$e');
  }
}
