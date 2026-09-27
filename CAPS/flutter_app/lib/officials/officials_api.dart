import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart' show ApiResult;

/// One current barangay official (`user/backend/officials.php`).
class Official {
  final int id;
  final String name;
  final String position;

  /// captain | executive | kagawad | sk | other
  final String group;
  final DateTime? termStart;
  final DateTime? termEnd;
  final String? photoUrl; // relative to ApiConfig.baseUrl

  const Official({
    required this.id,
    required this.name,
    required this.position,
    this.group = 'other',
    this.termStart,
    this.termEnd,
    this.photoUrl,
  });

  String get initials {
    final parts = name.split(' ').where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    final s = parts.length == 1
        ? parts.first[0]
        : '${parts.first[0]}${parts.last[0]}';
    return s.toUpperCase();
  }

  /// "Kagawad - Education" → committee "Education" (null if none).
  String? get committee {
    final i = position.indexOf(' - ');
    return i < 0 ? null : position.substring(i + 3);
  }

  factory Official.fromJson(Map<String, dynamic> j) => Official(
        id: int.tryParse(j['official_id']?.toString() ?? '') ?? 0,
        name: (j['name'] ?? '').toString(),
        position: (j['position'] ?? '').toString(),
        group: (j['group'] ?? 'other').toString(),
        termStart: DateTime.tryParse(j['term_start']?.toString() ?? ''),
        termEnd: DateTime.tryParse(j['term_end']?.toString() ?? ''),
        photoUrl: j['photo_url']?.toString(),
      );
}

/// HTTP client for `user/backend/officials.php`.
class OfficialsApi {
  OfficialsApi({http.Client? client, String? baseUrl})
      : _client = client ?? http.Client(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Future<ApiResult<List<Official>>> list() async {
    try {
      final res = await _client
          .get(Uri.parse('$_baseUrl/officials.php'), headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      Map<String, dynamic> body = {};
      try {
        final d = jsonDecode(res.body);
        if (d is Map<String, dynamic>) body = d;
      } catch (_) {}
      if (res.statusCode == 200 && body['success'] == true) {
        final list = (body['data']?['officials'] as List? ?? [])
            .map((e) => Official.fromJson(Map<String, dynamic>.from(e)))
            .toList();
        return ApiResult.success(list);
      }
      final msg = body['message']?.toString() ?? '';
      return ApiResult.failure(msg.isNotEmpty ? msg : tr.officialsLoadFailed);
    } on TimeoutException {
      return ApiResult.failure(tr.errTimeout);
    } on http.ClientException {
      return ApiResult.failure(tr.errNoConnection);
    } catch (e) {
      return ApiResult.failure(tr.errGeneric('$e'));
    }
  }

  void dispose() => _client.close();
}
