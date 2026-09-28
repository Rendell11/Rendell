import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart' show ApiResult;
import '../services/auth_client.dart';

/// One notification (`user/backend/notifications.php`).
class AppNotification {
  final String key; // "<source>:<id>"
  final String source; // rn | announcement | alert | blotter | hearing
  final String type;
  final String title;
  final String message;
  final String? refTable;
  final int? refId;
  final bool isRead;
  final DateTime? createdAt;

  const AppNotification({
    required this.key,
    required this.source,
    this.type = '',
    required this.title,
    this.message = '',
    this.refTable,
    this.refId,
    this.isRead = false,
    this.createdAt,
  });

  AppNotification read() => AppNotification(
        key: key,
        source: source,
        type: type,
        title: title,
        message: message,
        refTable: refTable,
        refId: refId,
        isRead: true,
        createdAt: createdAt,
      );

  factory AppNotification.fromJson(Map<String, dynamic> j) => AppNotification(
        key: (j['key'] ?? '').toString(),
        source: (j['source'] ?? '').toString(),
        type: (j['type'] ?? '').toString(),
        title: (j['title'] ?? '').toString(),
        message: (j['message'] ?? '').toString(),
        refTable: j['ref_table']?.toString(),
        refId: int.tryParse(j['ref_id']?.toString() ?? ''),
        isRead: j['is_read'] == true || j['is_read'] == 1,
        createdAt: DateTime.tryParse(j['created_at']?.toString() ?? ''),
      );

  /// Icon + colour per kind of update.
  (IconData, Color) get look {
    switch (type) {
      case 'document_ready_pickup':
        return (Icons.inventory_2_outlined, const Color(0xFF0EA5E9));
      case 'document_released':
        return (Icons.task_alt, const Color(0xFF16A34A));
      case 'document_rejected':
        return (Icons.block, const Color(0xFFEF4444));
      case 'complaint_update':
        return (Icons.report_problem_outlined, const Color(0xFFF59E0B));
      case 'disaster_alert':
      case 'emergency_notice':
        return (Icons.warning_amber_rounded, const Color(0xFFE11D48));
      case 'announcement':
        return (Icons.campaign_outlined, const Color(0xFF0EA5E9));
      case 'blotter_update':
        return (Icons.gavel_outlined, const Color(0xFF6366F1));
      case 'hearing_reminder':
        return (Icons.event_available, const Color(0xFF8B5CF6));
      default:
        return (Icons.notifications_outlined, const Color(0xFF1D63DA));
    }
  }

  /// "5m ago", "Yesterday", or the date.
  String get when {
    final d = createdAt;
    if (d == null) return '';
    final diff = DateTime.now().difference(d);
    if (diff.isNegative || diff.inMinutes < 1) return tr.justNow;
    if (diff.inHours < 1) return tr.minutesAgo(diff.inMinutes);
    if (diff.inDays < 1) return tr.hoursAgo(diff.inHours);
    if (diff.inDays < 7) return tr.daysAgo(diff.inDays);
    return '${tr.monthsShort[d.month - 1]} ${d.day}, ${d.year}';
  }
}

class NotificationCount {
  final int unread;
  final int activeAlerts;
  const NotificationCount(this.unread, this.activeAlerts);
}

/// HTTP client for `user/backend/notifications.php`.
class NotificationsApi {
  NotificationsApi({http.Client? client, String? baseUrl})
      : _client = client ?? AuthClient(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Uri _uri([Map<String, String>? q]) =>
      Uri.parse('$_baseUrl/notifications.php').replace(queryParameters: q);

  Future<ApiResult<Map<String, dynamic>>> _call(
      Future<http.Response> Function() f) async {
    try {
      final res = await f().timeout(ApiConfig.timeout);
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
      return ApiResult.failure(
          msg.isNotEmpty ? msg : tr.notificationsLoadFailed);
    } on TimeoutException {
      return ApiResult.failure(tr.errTimeout);
    } on http.ClientException {
      return ApiResult.failure(tr.errNoConnection);
    } catch (e) {
      return ApiResult.failure(tr.errGeneric('$e'));
    }
  }

  Future<ApiResult<List<AppNotification>>> list() async {
    final r = await _call(() =>
        _client.get(_uri({'action': 'list'}), headers: ApiConfig.headers));
    if (!r.ok) return ApiResult.failure(r.message);
    return ApiResult.success((r.data!['items'] as List? ?? [])
        .map((e) => AppNotification.fromJson(Map<String, dynamic>.from(e)))
        .toList());
  }

  Future<NotificationCount?> count() async {
    final r = await _call(() =>
        _client.get(_uri({'action': 'count'}), headers: ApiConfig.headers));
    if (!r.ok) return null;
    return NotificationCount(
      int.tryParse('${r.data!['unread']}') ?? 0,
      int.tryParse('${r.data!['active_alerts']}') ?? 0,
    );
  }

  Future<void> markRead(String key) => _call(() => _client.post(_uri(),
      headers: ApiConfig.headers, body: {'action': 'read', 'key': key}));

  Future<void> markAllRead() => _call(() => _client
      .post(_uri(), headers: ApiConfig.headers, body: {'action': 'read_all'}));

  /// Save this device's Firebase token so the server can send push messages.
  Future<void> registerDevice(String? pushToken) =>
      _call(() => _client.post(_uri(),
          headers: ApiConfig.headers,
          body: {'action': 'register_device', 'push_token': pushToken ?? ''}));

  void dispose() => _client.close();
}
