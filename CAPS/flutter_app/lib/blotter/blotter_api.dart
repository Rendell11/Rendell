import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart' show ApiResult;
import '../services/auth_client.dart';

String? _s(dynamic v) {
  final s = v?.toString().trim();
  return (s == null || s.isEmpty) ? null : s;
}

int _i(dynamic v) => int.tryParse(v?.toString() ?? '') ?? 0;
bool _b(dynamic v) => v == true || v == 1 || v == '1';
DateTime? _d(dynamic v) => DateTime.tryParse(v?.toString() ?? '');

/// Colours / icons per case status (same groups as admin blt_status_meta).
class BlotterStyle {
  static Color status(String s) {
    switch (s) {
      case 'Resolved':
      case 'Closed':
        return const Color(0xFF16A34A);
      case 'Hearing Scheduled':
      case 'Scheduled':
        return const Color(0xFF6366F1);
      case 'Notice Issued':
        return const Color(0xFF0EA5E9);
      case 'Hearing Completed':
        return const Color(0xFF8B5CF6);
      case 'For Next Hearing':
        return const Color(0xFFEA580C);
      case 'For Transfer':
      case 'Transferred':
        return const Color(0xFFE11D48);
      case 'Cancelled':
      case 'Withdrawn':
        return const Color(0xFF64748B);
      case 'Filed':
        return const Color(0xFF475569);
      default:
        return const Color(0xFFD97706);
    }
  }

  static IconData icon(String s) {
    switch (s) {
      case 'Resolved':
        return Icons.handshake_outlined;
      case 'Closed':
        return Icons.task_alt;
      case 'Hearing Scheduled':
      case 'Scheduled':
        return Icons.event;
      case 'Notice Pending':
      case 'Notice Issued':
        return Icons.mark_email_read_outlined;
      case 'Hearing Completed':
        return Icons.gavel;
      case 'For Next Hearing':
        return Icons.update;
      case 'For Transfer':
        return Icons.forward;
      case 'Transferred':
        return Icons.local_police_outlined;
      case 'Cancelled':
        return Icons.block;
      case 'Withdrawn':
        return Icons.undo;
      case 'For Hearing':
        return Icons.event_busy;
      default:
        return Icons.note_add_outlined;
    }
  }

  static Color role(String r) =>
      r == 'Respondent' ? const Color(0xFFE11D48) : const Color(0xFF1D63DA);

  /// "09:00:00" → "9:00 AM"
  static String time(String? t) {
    if (t == null || t.isEmpty) return '';
    final p = t.split(':');
    final h = int.tryParse(p[0]) ?? 0;
    final m = p.length > 1 ? p[1] : '00';
    return '${h % 12 == 0 ? 12 : h % 12}:$m ${h < 12 ? 'AM' : 'PM'}';
  }
}

class BlotterHearing {
  final int no;
  final DateTime? date;
  final String? time;
  final String? location;
  final String status;
  final String? cancelReason;
  final String? outcome;
  final String? remarks;

  const BlotterHearing({
    required this.no,
    this.date,
    this.time,
    this.location,
    this.status = 'Scheduled',
    this.cancelReason,
    this.outcome,
    this.remarks,
  });

  factory BlotterHearing.fromJson(Map<String, dynamic> j) => BlotterHearing(
        no: _i(j['no']),
        date: _d(j['date']),
        time: _s(j['time']),
        location: _s(j['location']),
        status: _s(j['status']) ?? 'Scheduled',
        cancelReason: _s(j['cancel_reason']),
        outcome: _s(j['outcome']),
        remarks: _s(j['remarks']),
      );
}

class BlotterParty {
  final String role;
  final String name;
  final bool isMe;

  const BlotterParty(
      {required this.role, required this.name, this.isMe = false});

  factory BlotterParty.fromJson(Map<String, dynamic> j) => BlotterParty(
        role: _s(j['role']) ?? 'Complainant',
        name: _s(j['name']) ?? '—',
        isMe: _b(j['is_me']),
      );
}

class BlotterNotice {
  final String? noticeNo;
  final String type;
  final DateTime? issuedAt;
  final DateTime? hearingDate;
  final String? hearingTime;

  const BlotterNotice(
      {this.noticeNo,
      required this.type,
      this.issuedAt,
      this.hearingDate,
      this.hearingTime});

  factory BlotterNotice.fromJson(Map<String, dynamic> j) => BlotterNotice(
        noticeNo: _s(j['notice_no']),
        type: _s(j['type']) ?? 'Notice',
        issuedAt: _d(j['issued_at']),
        hearingDate: _d(j['hearing_date']),
        hearingTime: _s(j['hearing_time']),
      );
}

class BlotterEvent {
  final String action;
  final String? status;
  final DateTime? at;

  const BlotterEvent({required this.action, this.status, this.at});

  factory BlotterEvent.fromJson(Map<String, dynamic> j) => BlotterEvent(
        action: _s(j['action']) ?? '',
        status: _s(j['status']),
        at: _d(j['at']),
      );
}

/// One blotter case the resident is part of (`user/backend/blotter.php`).
class BlotterCase {
  final int id;
  final String caseNumber;
  final String incidentType;
  final DateTime? incidentDate;
  final String? incidentTime;
  final String? location;
  final String status;
  final bool isActive;
  final String myRole; // Complainant | Respondent
  final DateTime? filedAt;
  final BlotterHearing? nextHearing;

  // Detail only.
  final String? narrative;
  final List<BlotterParty> parties;
  final List<BlotterHearing> hearings;
  final List<BlotterNotice> notices;
  final String? resolution;
  final DateTime? resolvedAt;
  final String? transferDestination;
  final DateTime? transferDate;
  final List<BlotterEvent> timeline;

  const BlotterCase({
    required this.id,
    required this.caseNumber,
    required this.incidentType,
    this.incidentDate,
    this.incidentTime,
    this.location,
    required this.status,
    this.isActive = false,
    required this.myRole,
    this.filedAt,
    this.nextHearing,
    this.narrative,
    this.parties = const [],
    this.hearings = const [],
    this.notices = const [],
    this.resolution,
    this.resolvedAt,
    this.transferDestination,
    this.transferDate,
    this.timeline = const [],
  });

  factory BlotterCase.fromJson(Map<String, dynamic> j) {
    List<T> list<T>(String k, T Function(Map<String, dynamic>) f) =>
        (j[k] as List? ?? [])
            .map((e) => f(Map<String, dynamic>.from(e)))
            .toList();
    final res = j['resolution'] is Map ? j['resolution'] as Map : null;
    final tr_ = j['transfer'] is Map ? j['transfer'] as Map : null;
    return BlotterCase(
      id: _i(j['id']),
      caseNumber: _s(j['case_number']) ?? '—',
      incidentType: _s(j['incident_type']) ?? '—',
      incidentDate: _d(j['incident_date']),
      incidentTime: _s(j['incident_time']),
      location: _s(j['location']),
      status: _s(j['status']) ?? 'Filed',
      isActive: _b(j['is_active']),
      myRole: _s(j['my_role']) ?? 'Complainant',
      filedAt: _d(j['filed_at']),
      nextHearing: j['next_hearing'] is Map
          ? BlotterHearing.fromJson(
              Map<String, dynamic>.from(j['next_hearing']))
          : null,
      narrative: _s(j['narrative']),
      parties: list('parties', BlotterParty.fromJson),
      hearings: list('hearings', BlotterHearing.fromJson),
      notices: list('notices', BlotterNotice.fromJson),
      resolution: _s(res?['details']),
      resolvedAt: _d(res?['at']),
      transferDestination: _s(tr_?['destination']),
      transferDate: _d(tr_?['date']),
      timeline: list('timeline', BlotterEvent.fromJson),
    );
  }
}

class BlotterList {
  final List<BlotterCase> cases;
  final int total;
  final int active;

  const BlotterList(this.cases, this.total, this.active);
}

/// HTTP client for `user/backend/blotter.php` (read-only).
class BlotterApi {
  BlotterApi({http.Client? client, String? baseUrl})
      : _client = client ?? AuthClient(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Future<ApiResult<Map<String, dynamic>>> _get(Map<String, String> q) async {
    try {
      final res = await _client
          .get(Uri.parse('$_baseUrl/blotter.php').replace(queryParameters: q),
              headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      Map<String, dynamic> body = {};
      try {
        final d = jsonDecode(res.body);
        if (d is Map<String, dynamic>) body = d;
      } catch (_) {}
      if (res.statusCode == 200 && body['success'] == true) {
        return ApiResult.success(
            Map<String, dynamic>.from(body['data'] as Map? ?? {}));
      }
      final msg = body['message']?.toString() ?? '';
      return ApiResult.failure(msg.isNotEmpty ? msg : tr.blotterLoadFailed);
    } on TimeoutException {
      return ApiResult.failure(tr.errTimeout);
    } on http.ClientException {
      return ApiResult.failure(tr.errNoConnection);
    } catch (e) {
      return ApiResult.failure(tr.errGeneric('$e'));
    }
  }

  Future<ApiResult<BlotterList>> list(int residentId) async {
    final r = await _get({'action': 'list', 'resident_id': '$residentId'});
    if (!r.ok) return ApiResult.failure(r.message);
    final d = r.data!;
    final counts = Map<String, dynamic>.from(d['counts'] as Map? ?? {});
    return ApiResult.success(BlotterList(
      (d['cases'] as List? ?? [])
          .map((e) => BlotterCase.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
      _i(counts['total']),
      _i(counts['active']),
    ));
  }

  Future<ApiResult<BlotterCase>> detail(int residentId, int id) async {
    final r = await _get(
        {'action': 'detail', 'resident_id': '$residentId', 'id': '$id'});
    if (!r.ok) return ApiResult.failure(r.message);
    return ApiResult.success(BlotterCase.fromJson(r.data!));
  }

  void dispose() => _client.close();
}
