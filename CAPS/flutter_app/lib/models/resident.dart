/// A subset of the `residents` table — the portal/self-service fields the
/// mobile app needs after a resident's access request is approved and they set
/// a password (`access_status` becomes `Active`).
class Resident {
  final int residentId;
  final String? residentCode;
  final String firstName;
  final String? middleName;
  final String lastName;
  final String? email;
  final String? contactNumber;
  final String? purok;
  final ResidentAccessStatus accessStatus;

  const Resident({
    required this.residentId,
    this.residentCode,
    required this.firstName,
    this.middleName,
    required this.lastName,
    this.email,
    this.contactNumber,
    this.purok,
    this.accessStatus = ResidentAccessStatus.none,
  });

  String get fullName => [
        firstName,
        if ((middleName ?? '').isNotEmpty) middleName,
        lastName,
      ].join(' ');

  /// Serialised with the same keys [fromJson] reads, so a stored session can be
  /// restored round-trip by SessionService.
  Map<String, dynamic> toJson() => {
        'ResidentID': residentId,
        'ResidentCode': residentCode,
        'FirstName': firstName,
        'MiddleName': middleName,
        'LastName': lastName,
        'Email': email,
        'ContactNumber': contactNumber,
        'Purok': purok,
        'access_status': accessStatus.name == 'none'
            ? 'None'
            : accessStatus == ResidentAccessStatus.active
                ? 'Active'
                : accessStatus == ResidentAccessStatus.pending
                    ? 'Pending'
                    : 'Disabled',
      };

  factory Resident.fromJson(Map<String, dynamic> json) => Resident(
        residentId: int.tryParse(json['ResidentID']?.toString() ?? '') ?? 0,
        residentCode: json['ResidentCode']?.toString(),
        firstName: (json['FirstName'] ?? '').toString(),
        middleName: json['MiddleName']?.toString(),
        lastName: (json['LastName'] ?? '').toString(),
        email: json['Email']?.toString(),
        contactNumber: json['ContactNumber']?.toString(),
        purok: json['Purok']?.toString(),
        accessStatus:
            ResidentAccessStatusX.fromDb(json['access_status']?.toString()),
      );
}

/// The `residents.access_status` enum: None / Pending / Active / Disabled.
enum ResidentAccessStatus { none, pending, active, disabled }

extension ResidentAccessStatusX on ResidentAccessStatus {
  static ResidentAccessStatus fromDb(String? value) {
    switch (value) {
      case 'Pending':
        return ResidentAccessStatus.pending;
      case 'Active':
        return ResidentAccessStatus.active;
      case 'Disabled':
        return ResidentAccessStatus.disabled;
      case 'None':
      default:
        return ResidentAccessStatus.none;
    }
  }
}
