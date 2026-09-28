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

/// Colours / icons for alert types and severities.
class DisasterStyle {
  static IconData icon(String? t) {
    switch (t) {
      case 'Flood':
        return Icons.water;
      case 'Fire':
        return Icons.local_fire_department;
      case 'Earthquake':
        return Icons.vibration;
      case 'Typhoon':
        return Icons.storm;
      default:
        return Icons.warning_amber_rounded;
    }
  }

  static Color severity(String? s) {
    switch (s) {
      case 'Critical':
        return const Color(0xFFB91C1C);
      case 'High':
        return const Color(0xFFE11D48);
      case 'Medium':
        return const Color(0xFFD97706);
      default:
        return const Color(0xFF0EA5E9);
    }
  }
}

/// One alert from Announcements → "Issue Alert" (admin).
class DisasterAlert {
  final int id;
  final String? type;
  final String? severity;
  final String title;
  final String? message;
  final String? location;
  final String? evacuation;
  final bool isActive;
  final DateTime? createdAt;

  const DisasterAlert({
    required this.id,
    this.type,
    this.severity,
    required this.title,
    this.message,
    this.location,
    this.evacuation,
    this.isActive = false,
    this.createdAt,
  });

  factory DisasterAlert.fromJson(Map<String, dynamic> j) => DisasterAlert(
        id: int.tryParse('${j['id']}') ?? 0,
        type: _s(j['type']),
        severity: _s(j['severity']),
        title: _s(j['title']) ?? '',
        message: _s(j['message']),
        location: _s(j['location']),
        evacuation: _s(j['evacuation']),
        isActive: j['is_active'] == true,
        createdAt: DateTime.tryParse('${j['created_at']}'),
      );
}

class DisasterData {
  final List<DisasterAlert> alerts;

  const DisasterData(this.alerts);

  List<DisasterAlert> get active => alerts.where((a) => a.isActive).toList();
}

/// HTTP client for `user/backend/disaster.php`.
class DisasterApi {
  DisasterApi({http.Client? client, String? baseUrl})
      : _client = client ?? AuthClient(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Future<ApiResult<DisasterData>> load() async {
    try {
      final res = await _client
          .get(Uri.parse('$_baseUrl/disaster.php'), headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      Map<String, dynamic> body = {};
      try {
        final d = jsonDecode(res.body);
        if (d is Map<String, dynamic>) body = d;
      } catch (_) {}
      if (res.statusCode == 200 && body['success'] == true) {
        final d = Map<String, dynamic>.from(body['data'] as Map? ?? {});
        return ApiResult.success(DisasterData((d['alerts'] as List? ?? [])
            .map((e) => DisasterAlert.fromJson(Map<String, dynamic>.from(e)))
            .toList()));
      }
      final msg = body['message']?.toString() ?? '';
      return ApiResult.failure(msg.isNotEmpty ? msg : tr.disasterLoadFailed);
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
