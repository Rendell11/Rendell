import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../models/access_request.dart';
import '../models/barangay_profile.dart';
import '../models/chat_message.dart';
import '../models/resident.dart';
import 'auth_client.dart';

/// Thin HTTP client for the resident access-request flow.
///
/// Every endpoint returns a JSON object shaped like:
///   { "success": true|false, "message": "...", "data": {...} }
/// which is wrapped in [ApiResult] so screens can react uniformly.
class ApiService {
  ApiService({http.Client? client, String? baseUrl})
      : _client = client ?? AuthClient(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Uri _uri(String path) => Uri.parse('$_baseUrl/$path');

  /// STEP 1 — resident submits a new portal access request.
  ///
  /// Sends the text fields plus (optionally) a photo of a valid ID and a
  /// selfie as multipart files, matching `access_requests.valid_id_path` and
  /// `access_requests.selfie_image`.
  Future<ApiResult<int>> submitAccessRequest(
    AccessRequest request, {
    Uint8List? validIdBytes,
    String? validIdName,
    Uint8List? selfieBytes,
    String? selfieName,
  }) async {
    try {
      final req = http.MultipartRequest('POST', _uri('request_access.php'))
        ..headers.addAll(ApiConfig.headers)
        ..fields.addAll(request.toRequestFields());

      // fromBytes works on web AND mobile (fromPath fails on Flutter Web,
      // where a picked file's "path" is a blob URL, not a real file).
      if (validIdBytes != null) {
        req.files.add(http.MultipartFile.fromBytes(
          'valid_id',
          validIdBytes,
          filename: validIdName ?? 'valid_id.jpg',
        ));
      }
      if (selfieBytes != null) {
        req.files.add(http.MultipartFile.fromBytes(
          'selfie',
          selfieBytes,
          filename: selfieName ?? 'selfie.jpg',
        ));
      }

      final streamed =
          await AuthClient.sendOnce(req).timeout(ApiConfig.timeout);
      final res = await http.Response.fromStream(streamed);
      final body = _decode(res);
      if (_ok(res, body)) {
        final id = int.tryParse(body['data']?['request_id']?.toString() ?? '');
        return ApiResult.success(
          id,
          message: body['message']?.toString() ?? tr.requestSubmitted,
        );
      }
      return ApiResult.failure(_msg(body, tr.requestSubmitFailed));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// STEP 2 — resident checks the status of their request by email or contact.
  Future<ApiResult<AccessRequest>> checkStatus({
    String? email,
    String? contactNumber,
  }) async {
    try {
      final res = await _client.post(
        _uri('check_status.php'),
        headers: ApiConfig.headers,
        body: {
          if (email != null) 'email': email,
          if (contactNumber != null) 'contact_number': contactNumber,
        },
      ).timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body) && body['data'] != null) {
        return ApiResult.success(
          AccessRequest.fromJson(Map<String, dynamic>.from(body['data'])),
        );
      }
      return ApiResult.failure(_msg(body, tr.requestNotFound));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// STEP 3 — after approval, resident sets their portal password using the
  /// token issued by the admin. On success `residents.access_status` becomes
  /// `Active`.
  Future<ApiResult<void>> setPassword({
    required String token,
    required String password,
  }) async {
    try {
      final res = await _client.post(
        _uri('set_password.php'),
        headers: ApiConfig.headers,
        body: {'token': token, 'password': password},
      ).timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body)) {
        return ApiResult.success(null,
            message: body['message']?.toString() ?? tr.passwordSet);
      }
      return ApiResult.failure(_msg(body, tr.passwordSetFailed));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// STEP 4 — resident logs in with email/contact + password.
  Future<ApiResult<Resident>> login({
    required String identifier,
    required String password,
  }) async {
    try {
      final res = await _client.post(
        _uri('login.php'),
        headers: ApiConfig.headers,
        body: {
          'identifier': identifier,
          'password': password,
          'platform': kIsWeb ? 'web' : defaultTargetPlatform.name,
        },
      ).timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body) && body['data'] != null) {
        // Login token for every later request (see SessionService).
        final token = body['data']['token']?.toString();
        if (token != null && token.isNotEmpty) ApiConfig.authToken = token;
        return ApiResult.success(
          Resident.fromJson(Map<String, dynamic>.from(body['data'])),
        );
      }
      return ApiResult.failure(_msg(body, tr.loginFailed));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// Request a password-reset email (SOE resident_forgot_password.php).
  /// Always resolves without revealing whether the email exists.
  Future<ApiResult<void>> forgotPassword(String email) async {
    try {
      final res = await _client.post(
        _uri('forgot_password.php'),
        headers: ApiConfig.headers,
        body: {'email': email},
      ).timeout(ApiConfig.timeout);
      final body = _decode(res);
      return ApiResult.success(null, message: _msg(body, ''));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// Admin-configured appearance for the pre-login screens (theme.php).
  /// Returns the accent hex + color mode; falls back to brand blue / light.
  Future<({String accent, String mode})> fetchPublicTheme() async {
    try {
      final res = await _client
          .get(_uri('theme.php'), headers: ApiConfig.headers)
          .timeout(const Duration(seconds: 5));
      final body = _decode(res);
      final data = body['data'];
      if (data is Map) {
        return (
          accent: (data['accent_color'] ?? '#1D63DA').toString(),
          mode: (data['color_mode'] ?? 'light').toString(),
        );
      }
    } catch (_) {}
    return (accent: '#1D63DA', mode: 'light');
  }

  /// Admin-managed default barangay address (barangay_profile). Null when the
  /// admin hasn't configured it yet.
  Future<BarangayProfile?> fetchBarangayProfile() async {
    try {
      final res = await _client
          .get(_uri('address.php?action=profile'), headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      final data = body['data'];
      if (_ok(res, body) && data is Map) {
        final profile =
            BarangayProfile.fromJson(Map<String, dynamic>.from(data));
        return profile.isConfigured ? profile : null;
      }
    } catch (_) {}
    return null;
  }

  /// Active streets for the barangay (resident_streets).
  Future<List<String>> fetchStreets(String barangayCode) async {
    try {
      final res = await _client
          .get(_uri('address.php?action=streets&barangay=$barangayCode'),
              headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      final data = body['data'];
      if (_ok(res, body) && data is List) {
        return data.map((e) => e.toString()).toList();
      }
    } catch (_) {}
    return const [];
  }

  /// Active areas (Purok/Subdivision/Village/Sitio) for the barangay.
  Future<List<AreaOption>> fetchAreas(String barangayCode) async {
    try {
      final res = await _client
          .get(_uri('address.php?action=areas&barangay=$barangayCode'),
              headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      final data = body['data'];
      if (_ok(res, body) && data is List) {
        return data
            .map((e) => AreaOption.fromJson(Map<String, dynamic>.from(e)))
            .toList();
      }
    } catch (_) {}
    return const [];
  }

  /// Purok dropdown options for the request form (from the `puroks` table).
  Future<List<String>> fetchPuroks() async {
    try {
      final res = await _client
          .get(_uri('puroks.php'), headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      final data = body['data'];
      if (_ok(res, body) && data is List) {
        return data.map((e) => e.toString()).toList();
      }
    } catch (_) {
      // fall through to defaults
    }
    return const ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5'];
  }

  // ── Chat (resident ⇄ barangay staff) ──────────────────────────────────────

  /// Load the resident's chat thread (messages + status + hotlines).
  Future<ApiResult<ChatThread>> chatList(int residentId) async {
    try {
      final res = await _client
          .get(_uri('chat.php?action=list&resident_id=$residentId'),
              headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body) && body['data'] is Map) {
        return ApiResult.success(
          ChatThread.fromJson(Map<String, dynamic>.from(body['data'])),
        );
      }
      return ApiResult.failure(_msg(body, tr.chatLoadFailed));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// Send a resident message (starts a new thread if the last one is resolved).
  Future<ApiResult<void>> chatSend({
    required int residentId,
    required String message,
    String category = 'General',
  }) async {
    try {
      final res = await _client.post(
        _uri('chat.php'),
        headers: ApiConfig.headers,
        body: {
          'action': 'send',
          'resident_id': '$residentId',
          'message': message,
          'category': category,
        },
      ).timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body)) {
        return ApiResult.success(null, message: _msg(body, ''));
      }
      return ApiResult.failure(_msg(body, tr.chatSendFailed));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// Mark the resident's thread as resolved/closed.
  Future<ApiResult<void>> chatEnd(int residentId) async {
    try {
      final res = await _client.post(
        _uri('chat.php'),
        headers: ApiConfig.headers,
        body: {'action': 'end', 'resident_id': '$residentId'},
      ).timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body)) {
        return ApiResult.success(null, message: _msg(body, ''));
      }
      return ApiResult.failure(_msg(body, tr.chatEndFailed));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  void dispose() => _client.close();

  // --- helpers --------------------------------------------------------------

  Map<String, dynamic> _decode(http.Response res) {
    if (res.body.isEmpty) return {};
    try {
      final decoded = jsonDecode(res.body);
      return decoded is Map<String, dynamic> ? decoded : {'data': decoded};
    } catch (_) {
      // Server returned HTML (a PHP error/warning) instead of JSON.
      return {'success': false, 'message': tr.errBadResponse};
    }
  }

  bool _ok(http.Response res, Map<String, dynamic> body) =>
      res.statusCode >= 200 &&
      res.statusCode < 300 &&
      (body['success'] == true || body['success'] == null && body.isNotEmpty);

  String _msg(Map<String, dynamic> body, String fallback) =>
      body['message']?.toString().isNotEmpty == true
          ? body['message'].toString()
          : fallback;

  String _friendly(Object e) {
    if (e is TimeoutException) {
      return tr.errTimeout;
    }
    if (e is http.ClientException) {
      return tr.errNoConnection;
    }
    return tr.errGeneric('$e');
  }
}

/// Uniform result wrapper for every API call.
class ApiResult<T> {
  final bool ok;
  final T? data;
  final String message;

  const ApiResult._(this.ok, this.data, this.message);

  factory ApiResult.success(T? data, {String message = ''}) =>
      ApiResult._(true, data, message);

  factory ApiResult.failure(String message) =>
      ApiResult._(false, null, message);
}
