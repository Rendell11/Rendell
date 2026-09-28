import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart' show ApiResult;

String? _s(dynamic v) {
  final s = v?.toString().trim();
  return (s == null || s.isEmpty) ? null : s;
}

int _i(dynamic v) => int.tryParse(v?.toString() ?? '') ?? 0;
bool _b(dynamic v) => v == true || v == 1 || v == '1';
DateTime? _d(dynamic v) => DateTime.tryParse(v?.toString() ?? '');
List<String> _strings(dynamic v) =>
    (v as List? ?? []).map((e) => e.toString()).toList();

/// Colours / icons per request status (admin cert_status_meta).
class CertStyle {
  static Color status(String s) {
    switch (s) {
      case 'Ready to Pick Up':
        return const Color(0xFF0EA5E9);
      case 'Released':
        return const Color(0xFF16A34A);
      case 'Rejected':
        return const Color(0xFFEF4444);
      case 'Expired':
        return const Color(0xFF64748B);
      case 'Preview':
        return const Color(0xFF6366F1);
      default:
        return const Color(0xFFD97706); // Pending / Review
    }
  }

  static IconData statusIcon(String s) {
    switch (s) {
      case 'Review':
        return Icons.rate_review_outlined;
      case 'Ready to Pick Up':
        return Icons.inventory_2_outlined;
      case 'Released':
        return Icons.task_alt;
      case 'Rejected':
        return Icons.block;
      case 'Expired':
        return Icons.hourglass_disabled;
      default:
        return Icons.schedule;
    }
  }

  /// Admin colour names (custom_document_types.color) → colour.
  static Color named(String? c) {
    switch (c) {
      case 'emerald':
      case 'green':
        return const Color(0xFF16A34A);
      case 'amber':
      case 'yellow':
      case 'orange':
        return const Color(0xFFD97706);
      case 'violet':
      case 'purple':
      case 'indigo':
        return const Color(0xFF7C3AED);
      case 'red':
      case 'rose':
        return const Color(0xFFE11D48);
      case 'sky':
      case 'cyan':
      case 'teal':
        return const Color(0xFF0891B2);
      case 'slate':
      case 'gray':
        return const Color(0xFF64748B);
      default:
        return const Color(0xFF1D63DA);
    }
  }

  /// Admin Material Symbols icon names → Flutter icons (common ones).
  static IconData namedIcon(String? i) =>
      _icons[i] ?? Icons.description_outlined;

  static const _icons = <String, IconData>{
    'verified': Icons.verified_outlined,
    'home': Icons.home_outlined,
    'house': Icons.house_outlined,
    'volunteer_activism': Icons.volunteer_activism_outlined,
    'storefront': Icons.storefront_outlined,
    'store': Icons.store_outlined,
    'work': Icons.work_outline,
    'badge': Icons.badge_outlined,
    'school': Icons.school_outlined,
    'gavel': Icons.gavel_outlined,
    'health_and_safety': Icons.health_and_safety_outlined,
    'family_restroom': Icons.family_restroom,
    'assignment': Icons.assignment_outlined,
    'article': Icons.article_outlined,
    'fact_check': Icons.fact_check_outlined,
    'approval': Icons.approval_outlined,
    'construction': Icons.construction_outlined,
    'local_shipping': Icons.local_shipping_outlined,
    'elderly': Icons.elderly,
    'accessible': Icons.accessible,
    'person': Icons.person_outline,
    'description': Icons.description_outlined,
  };
}

class CertExtraField {
  final String key;
  final String label;
  final String type; // text | number | date | textarea | select
  final List<String> options;
  final bool required;

  const CertExtraField({
    required this.key,
    required this.label,
    this.type = 'text',
    this.options = const [],
    this.required = false,
  });

  factory CertExtraField.fromJson(Map<String, dynamic> j) => CertExtraField(
        key: _s(j['key']) ?? '',
        label: _s(j['label']) ?? '',
        type: _s(j['type']) ?? 'text',
        options: _strings(j['options']),
        required: _b(j['required']),
      );
}

/// A document the resident can request (set up by the admin).
class CertDocType {
  final String name;
  final String? code;
  final String? description;
  final String? icon;
  final String? color;
  final List<String> requirements;
  final List<CertExtraField> extraFields;

  const CertDocType({
    required this.name,
    this.code,
    this.description,
    this.icon,
    this.color,
    this.requirements = const [],
    this.extraFields = const [],
  });

  factory CertDocType.fromJson(Map<String, dynamic> j) => CertDocType(
        name: _s(j['doc_type']) ?? '',
        code: _s(j['code']),
        description: _s(j['description']),
        icon: _s(j['icon']),
        color: _s(j['color']),
        requirements: _strings(j['requirements']),
        extraFields: (j['extra_fields'] as List? ?? [])
            .map((e) => CertExtraField.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
      );
}

class CertLog {
  final String status;
  final String? note;
  final DateTime? at;

  const CertLog({required this.status, this.note, this.at});

  factory CertLog.fromJson(Map<String, dynamic> j) => CertLog(
        status: _s(j['status']) ?? '',
        note: _s(j['note']),
        at: _d(j['at']),
      );
}

/// One document request (`document_requests`).
class CertRequest {
  final int id;
  final String? referenceNo;
  final String? docNumber;
  final String docType;
  final String? purpose;
  final String status;
  final DateTime? requestedAt;
  final DateTime? approvedAt;
  final DateTime? releasedAt;
  final String? rejectionReason;
  final DateTime? pickupUntil;
  final bool isUnread;

  // Detail only.
  final List<String> requirementsRequired;
  final List<String> requirementsChecked;
  final List<MapEntry<String, String>> extra;
  final List<String> files;
  final List<CertLog> history;
  final bool canCancel;

  const CertRequest({
    required this.id,
    this.referenceNo,
    this.docNumber,
    required this.docType,
    this.purpose,
    required this.status,
    this.requestedAt,
    this.approvedAt,
    this.releasedAt,
    this.rejectionReason,
    this.pickupUntil,
    this.isUnread = false,
    this.requirementsRequired = const [],
    this.requirementsChecked = const [],
    this.extra = const [],
    this.files = const [],
    this.history = const [],
    this.canCancel = false,
  });

  bool get isPending => status == 'Pending' || status == 'Review';
  bool get isApproved => status == 'Ready to Pick Up' || status == 'Released';
  bool get isRejected => status == 'Rejected' || status == 'Expired';

  factory CertRequest.fromJson(Map<String, dynamic> j) => CertRequest(
        id: _i(j['id']),
        referenceNo: _s(j['reference_no']),
        docNumber: _s(j['doc_number']),
        docType: _s(j['doc_type']) ?? '—',
        purpose: _s(j['purpose']),
        status: _s(j['status']) ?? 'Pending',
        requestedAt: _d(j['requested_at']),
        approvedAt: _d(j['approved_at']),
        releasedAt: _d(j['released_at']),
        rejectionReason: _s(j['rejection_reason']),
        pickupUntil: _d(j['pickup_until']),
        isUnread: _b(j['is_unread']),
        requirementsRequired: _strings(j['requirements_required']),
        requirementsChecked: _strings(j['requirements_checked']),
        extra: (j['extra'] as List? ?? [])
            .map((e) => MapEntry(
                (e['label'] ?? '').toString(), (e['value'] ?? '').toString()))
            .toList(),
        files: (j['files'] as List? ?? [])
            .map((e) => (e['label'] ?? '').toString())
            .toList(),
        history: (j['history'] as List? ?? [])
            .map((e) => CertLog.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
        canCancel: _b(j['can_cancel']),
      );
}

class CertList {
  final List<CertRequest> requests;
  final int total, pending, ready, released, rejected;

  const CertList(this.requests,
      {this.total = 0,
      this.pending = 0,
      this.ready = 0,
      this.released = 0,
      this.rejected = 0});
}

/// What the staff will see when they review (shown as a heads-up).
class CertNotices {
  final int activeBlotterCases;
  final int unclaimed;

  const CertNotices({this.activeBlotterCases = 0, this.unclaimed = 0});
}

/// A photo attached to a requirement.
class CertAttachment {
  final String label;
  final Uint8List bytes;
  final String filename;

  const CertAttachment(this.label, this.bytes, this.filename);
}

/// HTTP client for `user/backend/certificate.php`.
class CertificateApi {
  CertificateApi({http.Client? client, String? baseUrl})
      : _client = client ?? http.Client(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Uri _uri([Map<String, String>? q]) =>
      Uri.parse('$_baseUrl/certificate.php').replace(queryParameters: q);

  Future<ApiResult<Map<String, dynamic>>> _handle(
      Future<http.Response> Function() call, String fallback) async {
    try {
      final res = await call().timeout(ApiConfig.timeout);
      Map<String, dynamic> body = {};
      try {
        final d = jsonDecode(res.body);
        if (d is Map<String, dynamic>) body = d;
      } catch (_) {}
      final msg = body['message']?.toString() ?? '';
      if (res.statusCode == 200 && body['success'] == true) {
        return ApiResult.success(
            Map<String, dynamic>.from(body['data'] as Map? ?? {}),
            message: msg);
      }
      return ApiResult.failure(msg.isNotEmpty ? msg : fallback);
    } on TimeoutException {
      return ApiResult.failure(tr.errTimeout);
    } on http.ClientException {
      return ApiResult.failure(tr.errNoConnection);
    } catch (e) {
      return ApiResult.failure(tr.errGeneric('$e'));
    }
  }

  Future<ApiResult<Map<String, dynamic>>> _get(Map<String, String> q) =>
      _handle(() => _client.get(_uri(q), headers: ApiConfig.headers),
          tr.certLoadFailed);

  Future<ApiResult<List<CertDocType>>> types(int residentId) async {
    final r = await _get({'action': 'types', 'resident_id': '$residentId'});
    if (!r.ok) return ApiResult.failure(r.message);
    return ApiResult.success((r.data!['types'] as List? ?? [])
        .map((e) => CertDocType.fromJson(Map<String, dynamic>.from(e)))
        .toList());
  }

  Future<ApiResult<CertList>> list(int residentId) async {
    final r = await _get({'action': 'list', 'resident_id': '$residentId'});
    if (!r.ok) return ApiResult.failure(r.message);
    final c = Map<String, dynamic>.from(r.data!['counts'] as Map? ?? {});
    return ApiResult.success(CertList(
      (r.data!['requests'] as List? ?? [])
          .map((e) => CertRequest.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
      total: _i(c['total']),
      pending: _i(c['pending']),
      ready: _i(c['ready']),
      released: _i(c['released']),
      rejected: _i(c['rejected']),
    ));
  }

  Future<ApiResult<CertRequest>> detail(int residentId, int id) async {
    final r = await _get(
        {'action': 'detail', 'resident_id': '$residentId', 'id': '$id'});
    if (!r.ok) return ApiResult.failure(r.message);
    return ApiResult.success(CertRequest.fromJson(r.data!));
  }

  Future<ApiResult<CertNotices>> notices(int residentId) async {
    final r = await _get({'action': 'notices', 'resident_id': '$residentId'});
    if (!r.ok) return ApiResult.failure(r.message);
    return ApiResult.success(CertNotices(
      activeBlotterCases: _i(r.data!['active_blotter_cases']),
      unclaimed: (r.data!['unclaimed'] as List? ?? []).length,
    ));
  }

  /// Send an online request. Returns the saved request (with Reference No.).
  Future<ApiResult<CertRequest>> submit(
    int residentId, {
    required String docType,
    required String purpose,
    Map<String, String> extra = const {},
    List<String> requirements = const [],
    List<CertAttachment> attachments = const [],
  }) async {
    final r = await _handle(() async {
      final req = http.MultipartRequest('POST', _uri())
        ..headers.addAll(ApiConfig.headers)
        ..fields.addAll({
          'action': 'submit',
          'resident_id': '$residentId',
          'doc_type': docType,
          'purpose': purpose,
          'extra': jsonEncode(extra),
          'requirements': jsonEncode(requirements),
        });
      for (var i = 0; i < attachments.length; i++) {
        req.fields['labels[$i]'] = attachments[i].label;
        // fromBytes works on web AND mobile.
        req.files.add(http.MultipartFile.fromBytes(
            'files[$i]', attachments[i].bytes,
            filename: attachments[i].filename));
      }
      return http.Response.fromStream(await req.send());
    }, tr.errGeneric(''));
    if (!r.ok) return ApiResult.failure(r.message);
    return ApiResult.success(CertRequest.fromJson(r.data!), message: r.message);
  }

  Future<ApiResult<void>> cancel(int residentId, int id) async {
    final r = await _handle(
        () => _client.post(_uri(), headers: ApiConfig.headers, body: {
              'action': 'cancel',
              'resident_id': '$residentId',
              'id': '$id',
            }),
        tr.errGeneric(''));
    return r.ok
        ? ApiResult.success(null, message: r.message)
        : ApiResult.failure(r.message);
  }

  void dispose() => _client.close();
}
