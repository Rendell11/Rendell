import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart' show ApiResult;
import '../services/auth_client.dart';

bool _b(dynamic v) => v == true || v == 1 || v == '1';
int? _i(dynamic v) => v == null ? null : int.tryParse(v.toString());
String? _s(dynamic v) {
  final s = v?.toString().trim();
  return (s == null || s.isEmpty) ? null : s;
}

/// One person in the household (the head is included, first).
class HouseholdMember {
  final int residentId;
  final String name;
  final String firstName;
  final String lastName;
  final String? photoUrl; // relative to ApiConfig.baseUrl
  final bool isHead;
  final bool isMe;
  final String relationship; // English value from the database
  final String? sex;
  final int? age;
  final DateTime? birthDate;
  final String? civilStatus;
  final String? contactNumber;
  final String? employment;
  final String? education;
  final bool isSenior;
  final bool isPwd;
  final bool isVoter;
  final bool isSoloParent;

  const HouseholdMember({
    required this.residentId,
    required this.name,
    this.firstName = '',
    this.lastName = '',
    this.photoUrl,
    this.isHead = false,
    this.isMe = false,
    this.relationship = 'Member',
    this.sex,
    this.age,
    this.birthDate,
    this.civilStatus,
    this.contactNumber,
    this.employment,
    this.education,
    this.isSenior = false,
    this.isPwd = false,
    this.isVoter = false,
    this.isSoloParent = false,
  });

  String get initials {
    final a = firstName.isNotEmpty ? firstName[0] : '';
    final b = lastName.isNotEmpty ? lastName[0] : '';
    final s = '$a$b'.toUpperCase();
    return s.isEmpty ? '?' : s;
  }

  factory HouseholdMember.fromJson(Map<String, dynamic> j) => HouseholdMember(
        residentId: _i(j['resident_id']) ?? 0,
        name: (j['name'] ?? '').toString(),
        firstName: (j['first_name'] ?? '').toString(),
        lastName: (j['last_name'] ?? '').toString(),
        photoUrl: _s(j['photo_url']),
        isHead: _b(j['is_head']),
        isMe: _b(j['is_me']),
        relationship: _s(j['relationship']) ?? 'Member',
        sex: _s(j['sex']),
        age: _i(j['age']),
        birthDate: DateTime.tryParse(j['birth_date']?.toString() ?? ''),
        civilStatus: _s(j['civil_status']),
        contactNumber: _s(j['contact_number']),
        employment: _s(j['employment']),
        education: _s(j['education']),
        isSenior: _b(j['is_senior']),
        isPwd: _b(j['is_pwd']),
        isVoter: _b(j['is_voter']),
        isSoloParent: _b(j['is_solo_parent']),
      );
}

class HouseholdInfo {
  final String? householdId;
  final String headName;
  final String address;
  final String? purok;
  final String? houseType;
  final String? tenure;
  final double? monthlyIncome;
  final String? incomeClass;
  final DateTime? registered;
  final bool surveyOnFile;

  const HouseholdInfo({
    this.householdId,
    this.headName = '',
    this.address = '',
    this.purok,
    this.houseType,
    this.tenure,
    this.monthlyIncome,
    this.incomeClass,
    this.registered,
    this.surveyOnFile = false,
  });

  factory HouseholdInfo.fromJson(Map<String, dynamic> j) => HouseholdInfo(
        householdId: _s(j['household_id']),
        headName: (j['head_name'] ?? '').toString(),
        address: (j['address'] ?? '').toString(),
        purok: _s(j['purok']),
        houseType: _s(j['house_type']),
        tenure: _s(j['tenure']),
        monthlyIncome: double.tryParse(j['monthly_income']?.toString() ?? ''),
        incomeClass: _s(j['income_class']),
        registered: DateTime.tryParse(j['registered']?.toString() ?? ''),
        surveyOnFile: _b(j['survey_on_file']),
      );
}

class HouseholdStats {
  final int total, male, female, seniors, minors, pwd;

  const HouseholdStats({
    this.total = 0,
    this.male = 0,
    this.female = 0,
    this.seniors = 0,
    this.minors = 0,
    this.pwd = 0,
  });

  factory HouseholdStats.fromJson(Map<String, dynamic> j) => HouseholdStats(
        total: _i(j['total']) ?? 0,
        male: _i(j['male']) ?? 0,
        female: _i(j['female']) ?? 0,
        seniors: _i(j['seniors']) ?? 0,
        minors: _i(j['minors']) ?? 0,
        pwd: _i(j['pwd']) ?? 0,
      );
}

/// The resident's household (`user/backend/household.php`).
class Household {
  /// 'head', 'member' or 'none' (not linked to a household yet).
  final String role;
  final String? relationship;
  final HouseholdInfo? info;
  final List<HouseholdMember> members;
  final HouseholdStats stats;

  const Household({
    required this.role,
    this.relationship,
    this.info,
    this.members = const [],
    this.stats = const HouseholdStats(),
  });

  bool get isHead => role == 'head';
  bool get isLinked => role != 'none' && info != null;

  factory Household.fromJson(Map<String, dynamic> j) => Household(
        role: (j['role'] ?? 'none').toString(),
        relationship: _s(j['relationship']),
        info: j['household'] is Map
            ? HouseholdInfo.fromJson(Map<String, dynamic>.from(j['household']))
            : null,
        members: (j['members'] as List? ?? [])
            .map((e) => HouseholdMember.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
        stats: j['stats'] is Map
            ? HouseholdStats.fromJson(Map<String, dynamic>.from(j['stats']))
            : const HouseholdStats(),
      );
}

/// HTTP client for `user/backend/household.php`.
class HouseholdApi {
  HouseholdApi({http.Client? client, String? baseUrl})
      : _client = client ?? AuthClient(),
        _baseUrl = baseUrl ?? ApiConfig.baseUrl;

  final http.Client _client;
  final String _baseUrl;

  Future<ApiResult<Household>> get(int residentId) async {
    try {
      final res = await _client
          .get(
              Uri.parse('$_baseUrl/household.php')
                  .replace(queryParameters: {'resident_id': '$residentId'}),
              headers: ApiConfig.headers)
          .timeout(ApiConfig.timeout);
      final body = _decode(res);
      if (res.statusCode == 200 && body['success'] == true) {
        return ApiResult.success(Household.fromJson(
            Map<String, dynamic>.from(body['data'] as Map? ?? {})));
      }
      final msg = body['message']?.toString() ?? '';
      return ApiResult.failure(msg.isNotEmpty ? msg : tr.householdLoadFailed);
    } on TimeoutException {
      return ApiResult.failure(tr.errTimeout);
    } on http.ClientException {
      return ApiResult.failure(tr.errNoConnection);
    } catch (e) {
      return ApiResult.failure(tr.errGeneric('$e'));
    }
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
