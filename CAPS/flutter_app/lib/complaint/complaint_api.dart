import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../services/api_service.dart' show ApiResult;
import 'complaint_model.dart';

/// HTTP client for `user/backend/complaint.php`.
///
/// Kept inside the `complaint/` folder (like `dashboard/` and `chat/`) so the
/// module can be debugged on its own; it reuses [ApiConfig] and [ApiResult]
/// and the same `{ success, message, data }` envelope as [ApiService].
class ComplaintApi {
  ComplaintApi({http.Client? client, String? baseUrl})
      : _client = client ?? http.Client(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  static const _endpoint = 'complaint.php';

  Uri _uri([Map<String, String>? query]) =>
      Uri.parse('$_baseUrl/$_endpoint').replace(queryParameters: query);

  /// Full URL for an attachment path returned by the server.
  String attachmentUrl(String relative) => '$_baseUrl/$relative';

  /// Category + priority lists (falls back to the bundled defaults).
  Future<({List<String> categories, List<String> priorities})>
      fetchOptions() async {
    try {
      final res = await _client
          .get(_uri({'action': 'categories'}))
          .timeout(ApiConfig.timeout);
      final data = _decode(res)['data'];
      if (data is Map) {
        final cats = (data['categories'] as List? ?? [])
            .map((e) => e.toString())
            .toList();
        final pris = (data['priorities'] as List? ?? [])
            .map((e) => e.toString())
            .toList();
        if (cats.isNotEmpty && pris.isNotEmpty) {
          return (categories: cats, priorities: pris);
        }
      }
    } catch (_) {}
    return (
      categories: kComplaintCategories,
      priorities: kComplaintPriorities,
    );
  }

  /// The resident's complaints + summary counts.
  Future<ApiResult<ComplaintList>> list(int residentId) async {
    try {
      final res = await _client
          .get(_uri({'action': 'list', 'resident_id': '$residentId'}))
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body) && body['data'] is Map) {
        return ApiResult.success(
          ComplaintList.fromJson(Map<String, dynamic>.from(body['data'])),
        );
      }
      return ApiResult.failure(_msg(body, 'Hindi ma-load ang mga reklamo.'));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// One complaint. The server also marks its latest update as seen.
  Future<ApiResult<Complaint>> detail(int residentId, int id) async {
    try {
      final res = await _client
          .get(_uri({
            'action': 'detail',
            'resident_id': '$residentId',
            'id': '$id',
          }))
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (_ok(res, body) && body['data'] is Map) {
        return ApiResult.success(
          Complaint.fromJson(Map<String, dynamic>.from(body['data'])),
        );
      }
      return ApiResult.failure(_msg(body, 'Hindi ma-load ang reklamo.'));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  /// File a new complaint, with an optional photo attachment.
  /// Returns the new `CMP-…` code on success.
  Future<ApiResult<String>> submit(
    int residentId,
    ComplaintDraft draft, {
    Uint8List? attachmentBytes,
    String? attachmentName,
  }) async {
    try {
      final req = http.MultipartRequest('POST', _uri())
        ..fields.addAll(draft.toFields(residentId));
      // fromBytes works on web AND mobile (see ApiService.submitAccessRequest).
      if (attachmentBytes != null) {
        req.files.add(http.MultipartFile.fromBytes(
          'attachment',
          attachmentBytes,
          filename: attachmentName ?? 'attachment.jpg',
        ));
      }
      final streamed = await req.send().timeout(ApiConfig.timeout);
      final res = await http.Response.fromStream(streamed);
      final body = _decode(res);
      if (_ok(res, body)) {
        return ApiResult.success(
          body['data']?['complaint_id']?.toString(),
          message: _msg(body, 'Naisumite ang reklamo.'),
        );
      }
      return ApiResult.failure(_msg(body, 'Hindi naisumite ang reklamo.'));
    } catch (e) {
      return ApiResult.failure(_friendly(e));
    }
  }

  void dispose() => _client.close();

  // --- helpers (same behaviour as ApiService) --------------------------------

  Map<String, dynamic> _decode(http.Response res) {
    if (res.body.isEmpty) return {};
    try {
      final decoded = jsonDecode(res.body);
      return decoded is Map<String, dynamic> ? decoded : {'data': decoded};
    } catch (_) {
      return {'success': false, 'message': 'Hindi wastong sagot ng server.'};
    }
  }

  bool _ok(http.Response res, Map<String, dynamic> body) =>
      res.statusCode >= 200 && res.statusCode < 300 && body['success'] == true;

  String _msg(Map<String, dynamic> body, String fallback) =>
      body['message']?.toString().isNotEmpty == true
          ? body['message'].toString()
          : fallback;

  String _friendly(Object e) {
    if (e is TimeoutException) {
      return 'Nag-timeout ang server. Subukan muli.';
    }
    if (e is http.ClientException) {
      return 'Hindi makakonekta sa server. Tingnan ang koneksyon at API base URL.';
    }
    return 'Nagkaproblema: $e';
  }
}
