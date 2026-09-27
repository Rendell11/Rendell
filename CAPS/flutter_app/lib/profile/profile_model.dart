/// The resident's profile as returned by `user/backend/profile.php?action=get`.
/// Read-only in the app for now, except the profile picture.
class ResidentProfile {
  final int residentId;
  final String? residentCode;
  final String? photoUrl; // relative to ApiConfig.baseUrl
  final String firstName;
  final String? middleName;
  final String lastName;
  final String? suffix;
  final String? sex;
  final String? civilStatus;
  final DateTime? birthDate;
  final int? age;
  final String? birthPlace;
  final String? religion;
  final String? nationality;
  final String? email;
  final String? contactNumber;
  final String? houseNumber;
  final String? street;
  final String? purok;
  final bool? isHead;
  final String? relationship;
  final String? householdHead;
  final String? employment;
  final String? education;
  final double? householdIncome;
  final bool? isVoter;
  final bool? isPwd;
  final String? pwdClass;
  final bool? isSenior;
  final bool? isSoloParent;
  final bool? hasPhilhealth;
  final bool? hasSssGsis;
  final bool? has4ps;
  final DateTime? memberSince;

  const ResidentProfile({
    required this.residentId,
    this.residentCode,
    this.photoUrl,
    required this.firstName,
    this.middleName,
    required this.lastName,
    this.suffix,
    this.sex,
    this.civilStatus,
    this.birthDate,
    this.age,
    this.birthPlace,
    this.religion,
    this.nationality,
    this.email,
    this.contactNumber,
    this.houseNumber,
    this.street,
    this.purok,
    this.isHead,
    this.relationship,
    this.householdHead,
    this.employment,
    this.education,
    this.householdIncome,
    this.isVoter,
    this.isPwd,
    this.pwdClass,
    this.isSenior,
    this.isSoloParent,
    this.hasPhilhealth,
    this.hasSssGsis,
    this.has4ps,
    this.memberSince,
  });

  String get fullName => [
        firstName,
        if ((middleName ?? '').isNotEmpty) middleName,
        lastName,
        if ((suffix ?? '').isNotEmpty) suffix,
      ].join(' ');

  String get initials {
    final s = [
      if (firstName.isNotEmpty) firstName[0],
      if (lastName.isNotEmpty) lastName[0],
    ].join().toUpperCase();
    return s.isEmpty ? '?' : s;
  }

  String get address => [
        if ((houseNumber ?? '').isNotEmpty) houseNumber,
        if ((street ?? '').isNotEmpty) street,
      ].join(' ');

  ResidentProfile withPhoto(String? url) => ResidentProfile(
        residentId: residentId,
        residentCode: residentCode,
        photoUrl: url,
        firstName: firstName,
        middleName: middleName,
        lastName: lastName,
        suffix: suffix,
        sex: sex,
        civilStatus: civilStatus,
        birthDate: birthDate,
        age: age,
        birthPlace: birthPlace,
        religion: religion,
        nationality: nationality,
        email: email,
        contactNumber: contactNumber,
        houseNumber: houseNumber,
        street: street,
        purok: purok,
        isHead: isHead,
        relationship: relationship,
        householdHead: householdHead,
        employment: employment,
        education: education,
        householdIncome: householdIncome,
        isVoter: isVoter,
        isPwd: isPwd,
        pwdClass: pwdClass,
        isSenior: isSenior,
        isSoloParent: isSoloParent,
        hasPhilhealth: hasPhilhealth,
        hasSssGsis: hasSssGsis,
        has4ps: has4ps,
        memberSince: memberSince,
      );

  factory ResidentProfile.fromJson(Map<String, dynamic> j) {
    String? s(String k) {
      final v = j[k]?.toString().trim();
      return (v == null || v.isEmpty) ? null : v;
    }

    bool? b(String k) => j[k] == null ? null : (j[k] == true || j[k] == 1);

    return ResidentProfile(
      residentId: int.tryParse(j['resident_id']?.toString() ?? '') ?? 0,
      residentCode: s('resident_code'),
      photoUrl: s('photo_url'),
      firstName: s('first_name') ?? '',
      middleName: s('middle_name'),
      lastName: s('last_name') ?? '',
      suffix: s('suffix'),
      sex: s('sex'),
      civilStatus: s('civil_status'),
      birthDate: DateTime.tryParse(s('birth_date') ?? ''),
      age: int.tryParse(j['age']?.toString() ?? ''),
      birthPlace: s('birth_place'),
      religion: s('religion'),
      nationality: s('nationality'),
      email: s('email'),
      contactNumber: s('contact_number'),
      houseNumber: s('house_number'),
      street: s('street'),
      purok: s('purok'),
      isHead: b('is_head'),
      relationship: s('relationship'),
      householdHead: s('household_head'),
      employment: s('employment'),
      education: s('education'),
      householdIncome: double.tryParse(j['household_income']?.toString() ?? ''),
      isVoter: b('is_voter'),
      isPwd: b('is_pwd'),
      pwdClass: s('pwd_class'),
      isSenior: b('is_senior'),
      isSoloParent: b('is_solo_parent'),
      hasPhilhealth: b('has_philhealth'),
      hasSssGsis: b('has_sss_gsis'),
      has4ps: b('has_4ps'),
      memberSince: DateTime.tryParse(s('member_since') ?? ''),
    );
  }
}
