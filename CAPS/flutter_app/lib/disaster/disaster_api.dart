import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:latlong2/latlong.dart';

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart' show ApiResult;
import '../services/auth_client.dart';

double? _f(dynamic v) => v == null ? null : double.tryParse(v.toString());
String? _s(dynamic v) {
  final s = v?.toString().trim();
  return (s == null || s.isEmpty) ? null : s;
}

/// Colours / icons for alert types, hazard types and severities.
class DisasterStyle {
  static Color type(String? t) {
    switch (t) {
      case 'Flood':
        return const Color(0xFF0284C7);
      case 'Fire':
        return const Color(0xFFEA580C);
      case 'Earthquake':
        return const Color(0xFF92400E);
      case 'Structural':
        return const Color(0xFF7C3AED);
      case 'Safe Point':
        return const Color(0xFF16A34A);
      default:
        return const Color(0xFFE11D48);
    }
  }

  static IconData icon(String? t) {
    switch (t) {
      case 'Flood':
        return Icons.water;
      case 'Fire':
        return Icons.local_fire_department;
      case 'Earthquake':
        return Icons.vibration;
      case 'Structural':
        return Icons.domain_disabled;
      case 'Safe Point':
        return Icons.health_and_safety;
      case 'Typhoon':
      case 'Storm':
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

class DisasterAlert {
  final int id;
  final String? type;
  final String? severity;
  final String title;
  final String? message;
  final String? location;
  final String? evacuation;
  final LatLng? point;
  final int radius;
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
    this.point,
    this.radius = 200,
    this.isActive = false,
    this.createdAt,
  });

  factory DisasterAlert.fromJson(Map<String, dynamic> j) {
    final lat = _f(j['lat']), lng = _f(j['lng']);
    return DisasterAlert(
      id: int.tryParse('${j['id']}') ?? 0,
      type: _s(j['type']),
      severity: _s(j['severity']),
      title: _s(j['title']) ?? '',
      message: _s(j['message']),
      location: _s(j['location']),
      evacuation: _s(j['evacuation']),
      point: lat != null && lng != null ? LatLng(lat, lng) : null,
      radius: int.tryParse('${j['radius']}') ?? 200,
      isActive: j['is_active'] == true,
      createdAt: DateTime.tryParse('${j['created_at']}'),
    );
  }
}

class Hazard {
  final int id;
  final String title;
  final String type;
  final String? description;
  final LatLng point;
  final int radius;

  const Hazard({
    required this.id,
    required this.title,
    required this.type,
    this.description,
    required this.point,
    this.radius = 100,
  });

  bool get isSafePoint => type == 'Safe Point';

  factory Hazard.fromJson(Map<String, dynamic> j) => Hazard(
        id: int.tryParse('${j['id']}') ?? 0,
        title: _s(j['title']) ?? '',
        type: _s(j['type']) ?? '',
        description: _s(j['description']),
        point: LatLng(_f(j['lat']) ?? 0, _f(j['lng']) ?? 0),
        radius: int.tryParse('${j['radius']}') ?? 100,
      );
}

class DisasterData {
  final List<DisasterAlert> alerts;
  final List<Hazard> hazards;
  final LatLng? home;
  final LatLng center;

  const DisasterData(this.alerts, this.hazards, this.home, this.center);

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
        LatLng? ll(dynamic m) => m is Map && _f(m['lat']) != null
            ? LatLng(_f(m['lat'])!, _f(m['lng'])!)
            : null;
        return ApiResult.success(DisasterData(
          (d['alerts'] as List? ?? [])
              .map((e) => DisasterAlert.fromJson(Map<String, dynamic>.from(e)))
              .toList(),
          (d['hazards'] as List? ?? [])
              .map((e) => Hazard.fromJson(Map<String, dynamic>.from(e)))
              .toList(),
          ll(d['home']),
          ll(d['center']) ?? const LatLng(14.8036, 120.9338),
        ));
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
