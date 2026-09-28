import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart' show ApiResult;
import '../services/auth_client.dart';

/// A file attached to an announcement (announcement_attachments).
class AnnouncementFile {
  final String name;
  final String url; // relative to ApiConfig.baseUrl
  final String ext;
  final int size;
  final bool isImage;

  const AnnouncementFile({
    required this.name,
    required this.url,
    this.ext = '',
    this.size = 0,
    this.isImage = false,
  });

  String get fullUrl => '${ApiConfig.baseUrl}/$url';

  factory AnnouncementFile.fromJson(Map<String, dynamic> j) => AnnouncementFile(
        name: (j['name'] ?? '').toString(),
        url: (j['url'] ?? '').toString(),
        ext: (j['ext'] ?? '').toString(),
        size: int.tryParse(j['size']?.toString() ?? '') ?? 0,
        isImage: j['is_image'] == true || j['is_image'] == 1,
      );
}

/// One published announcement (`user/backend/announcements.php`).
class Announcement {
  final int id;
  final String title;
  final String category;
  final bool isEmergency;
  final String details;
  final DateTime? datePosted;
  final DateTime? dateStart;
  final DateTime? dateEnd;
  final String? timeStart; // HH:MM:SS
  final String? timeEnd;
  final bool isEnded;
  final bool isNew;
  final List<AnnouncementFile> attachments;

  const Announcement({
    required this.id,
    required this.title,
    required this.category,
    this.isEmergency = false,
    this.details = '',
    this.datePosted,
    this.dateStart,
    this.dateEnd,
    this.timeStart,
    this.timeEnd,
    this.isEnded = false,
    this.isNew = false,
    this.attachments = const [],
  });

  List<AnnouncementFile> get images =>
      attachments.where((a) => a.isImage).toList();
  List<AnnouncementFile> get files =>
      attachments.where((a) => !a.isImage).toList();
  AnnouncementFile? get cover => images.isEmpty ? null : images.first;

  Announcement asRead() => Announcement(
        id: id,
        title: title,
        category: category,
        isEmergency: isEmergency,
        details: details,
        datePosted: datePosted,
        dateStart: dateStart,
        dateEnd: dateEnd,
        timeStart: timeStart,
        timeEnd: timeEnd,
        isEnded: isEnded,
        isNew: false,
        attachments: attachments,
      );

  factory Announcement.fromJson(Map<String, dynamic> j) {
    DateTime? d(String k) => DateTime.tryParse(j[k]?.toString() ?? '');
    String? s(String k) {
      final v = j[k]?.toString();
      return (v == null || v.isEmpty) ? null : v;
    }

    return Announcement(
      id: int.tryParse(j['id']?.toString() ?? '') ?? 0,
      title: (j['title'] ?? '').toString(),
      category: (j['category'] ?? 'General').toString(),
      isEmergency: j['is_emergency'] == true || j['is_emergency'] == 1,
      details: (j['details'] ?? '').toString(),
      datePosted: d('date_posted'),
      dateStart: d('date_start'),
      dateEnd: d('date_end'),
      timeStart: s('time_start'),
      timeEnd: s('time_end'),
      isEnded: j['is_ended'] == true || j['is_ended'] == 1,
      isNew: j['is_new'] == true || j['is_new'] == 1,
      attachments: (j['attachments'] as List? ?? [])
          .map((e) => AnnouncementFile.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
    );
  }
}

/// HTTP client for `user/backend/announcements.php`.
class AnnouncementApi {
  AnnouncementApi({http.Client? client, String? baseUrl})
      : _client = client ?? AuthClient(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Uri _uri([Map<String, String>? q]) =>
      Uri.parse('$_baseUrl/announcements.php').replace(queryParameters: q);

  Future<ApiResult<List<Announcement>>> list(int residentId) async {
    try {
      final res = await _client
          .get(_uri({'action': 'list', 'resident_id': '$residentId'}),
              headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (res.statusCode == 200 && body['success'] == true) {
        return ApiResult.success((body['data']?['announcements'] as List? ?? [])
            .map((e) => Announcement.fromJson(Map<String, dynamic>.from(e)))
            .toList());
      }
      final msg = body['message']?.toString() ?? '';
      return ApiResult.failure(
          msg.isNotEmpty ? msg : tr.announcementsLoadFailed);
    } on TimeoutException {
      return ApiResult.failure(tr.errTimeout);
    } on http.ClientException {
      return ApiResult.failure(tr.errNoConnection);
    } catch (e) {
      return ApiResult.failure(tr.errGeneric('$e'));
    }
  }

  /// Mark as seen (removes the "New" badge). Best-effort.
  Future<void> markRead(int residentId, int id) async {
    try {
      await _client.post(_uri(), headers: ApiConfig.headers, body: {
        'action': 'read',
        'resident_id': '$residentId',
        'id': '$id',
      }).timeout(const Duration(seconds: 8));
    } catch (_) {}
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
