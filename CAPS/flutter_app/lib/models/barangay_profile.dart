/// The admin-managed default barangay address (from `barangay_profile`,
/// set via the admin "Manage Area" PSGC picker). The request form shows these
/// as read-only instead of hard-coding Biñang 2nd / Bocaue / Bulacan.
class BarangayProfile {
  final String regionName;
  final String provinceName;
  final String municipalityName;
  final String barangayName;
  final String barangayCode; // psgc_barangay_code — keys streets & areas
  final String zipCode;

  const BarangayProfile({
    required this.regionName,
    required this.provinceName,
    required this.municipalityName,
    required this.barangayName,
    required this.barangayCode,
    required this.zipCode,
  });

  bool get isConfigured => barangayCode.isNotEmpty;

  factory BarangayProfile.fromJson(Map<String, dynamic> j) => BarangayProfile(
        regionName: (j['region_name'] ?? '').toString(),
        provinceName: (j['province_name'] ?? '').toString(),
        municipalityName: (j['municipality_name'] ?? '').toString(),
        barangayName: (j['barangay_name'] ?? '').toString(),
        barangayCode: (j['psgc_barangay_code'] ?? '').toString(),
        zipCode: (j['zip_code'] ?? '').toString(),
      );
}

/// One admin-managed area (Purok / Subdivision / Village / Sitio).
class AreaOption {
  final String name;
  final String type;

  const AreaOption({required this.name, required this.type});

  String get label => type.isEmpty ? name : '$name ($type)';

  factory AreaOption.fromJson(Map<String, dynamic> j) => AreaOption(
        name: (j['area_name'] ?? '').toString(),
        type: (j['area_type'] ?? '').toString(),
      );
}
