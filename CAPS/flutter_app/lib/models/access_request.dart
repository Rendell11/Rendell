import '../l10n/app_text.dart';

/// Mirrors a row of the `access_requests` table (Barangay DB rebuild script).
///
/// The resident self-service portal creates one of these when a resident asks
/// for a portal account. The admin then moves it through the `status` values
/// below. See [AccessStatus] for the meaning of each state.
class AccessRequest {
  final int? id;
  final int? requestId;
  final String firstName;
  final String? middleName;
  final String lastName;
  final String email;
  final String? contactNumber;
  final DateTime? birthdate;
  final String? houseNo;
  final String? street;
  final String? purok;
  final String? validIdPath;
  final String? selfieImage;
  final String? requestMessage;
  final AccessStatus status;
  final String? adminReason;
  final int? residentId;
  final DateTime? submittedAt;

  const AccessRequest({
    this.id,
    this.requestId,
    required this.firstName,
    this.middleName,
    required this.lastName,
    required this.email,
    this.contactNumber,
    this.birthdate,
    this.houseNo,
    this.street,
    this.purok,
    this.validIdPath,
    this.selfieImage,
    this.requestMessage,
    this.status = AccessStatus.pending,
    this.adminReason,
    this.residentId,
    this.submittedAt,
  });

  String get fullName => [
        firstName,
        if ((middleName ?? '').isNotEmpty) middleName,
        lastName,
      ].join(' ');

  factory AccessRequest.fromJson(Map<String, dynamic> json) => AccessRequest(
        id: _toInt(json['id']),
        requestId: _toInt(json['request_id']),
        firstName: (json['first_name'] ?? json['firstname'] ?? '').toString(),
        middleName: json['middle_name']?.toString(),
        lastName: (json['last_name'] ?? json['lastname'] ?? '').toString(),
        email: (json['email'] ?? '').toString(),
        contactNumber: json['contact_number']?.toString(),
        birthdate: _toDate(json['birthdate']),
        houseNo: json['house_no']?.toString(),
        street: json['street']?.toString(),
        purok: json['purok']?.toString(),
        validIdPath: json['valid_id_path']?.toString(),
        selfieImage: json['selfie_image']?.toString(),
        requestMessage: json['request_message']?.toString(),
        status: AccessStatusX.fromDb(json['status']?.toString()),
        adminReason: json['admin_reason']?.toString(),
        residentId: _toInt(json['resident_id']),
        submittedAt: _toDate(json['submitted_at']),
      );

  /// Fields the mobile app sends when submitting a new request. File fields
  /// (`valid_id_path`, `selfie_image`) are sent separately as multipart uploads
  /// by [ApiService.submitAccessRequest].
  Map<String, String> toRequestFields() => {
        'first_name': firstName,
        if ((middleName ?? '').isNotEmpty) 'middle_name': middleName!,
        'last_name': lastName,
        'email': email,
        if ((contactNumber ?? '').isNotEmpty) 'contact_number': contactNumber!,
        if (birthdate != null)
          'birthdate': birthdate!.toIso8601String().split('T').first,
        if ((houseNo ?? '').isNotEmpty) 'house_no': houseNo!,
        if ((street ?? '').isNotEmpty) 'street': street!,
        if ((purok ?? '').isNotEmpty) 'purok': purok!,
        if ((requestMessage ?? '').isNotEmpty)
          'request_message': requestMessage!,
      };
}

/// The `status` enum of `access_requests`.
enum AccessStatus {
  pending,
  approved,
  disapproved,
  matched,
  forProfiling,
  forCorrection,
  rejected,
}

extension AccessStatusX on AccessStatus {
  /// The exact string stored in the DB `enum`.
  String get db {
    switch (this) {
      case AccessStatus.pending:
        return 'Pending';
      case AccessStatus.approved:
        return 'Approved';
      case AccessStatus.disapproved:
        return 'Disapproved';
      case AccessStatus.matched:
        return 'Matched';
      case AccessStatus.forProfiling:
        return 'For Profiling';
      case AccessStatus.forCorrection:
        return 'For Correction';
      case AccessStatus.rejected:
        return 'Rejected';
    }
  }

  /// A short, resident-friendly explanation shown on the status screen.
  String get description {
    switch (this) {
      case AccessStatus.pending:
        return tr.statusPendingDesc;
      case AccessStatus.approved:
      case AccessStatus.matched:
        return tr.statusApprovedDesc;
      case AccessStatus.forProfiling:
        return tr.statusProfilingDesc;
      case AccessStatus.forCorrection:
        return tr.statusCorrectionDesc;
      case AccessStatus.disapproved:
      case AccessStatus.rejected:
        return tr.statusRejectedDesc;
    }
  }

  /// True when the resident can proceed to set a password.
  bool get canSetPassword =>
      this == AccessStatus.approved || this == AccessStatus.matched;

  static AccessStatus fromDb(String? value) {
    switch (value) {
      case 'Approved':
        return AccessStatus.approved;
      case 'Disapproved':
        return AccessStatus.disapproved;
      case 'Matched':
        return AccessStatus.matched;
      case 'For Profiling':
        return AccessStatus.forProfiling;
      case 'For Correction':
        return AccessStatus.forCorrection;
      case 'Rejected':
        return AccessStatus.rejected;
      case 'Pending':
      default:
        return AccessStatus.pending;
    }
  }
}

int? _toInt(dynamic v) {
  if (v == null) return null;
  if (v is int) return v;
  return int.tryParse(v.toString());
}

DateTime? _toDate(dynamic v) {
  if (v == null) return null;
  return DateTime.tryParse(v.toString());
}
