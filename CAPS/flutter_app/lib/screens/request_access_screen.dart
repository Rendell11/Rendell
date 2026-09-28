import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import '../l10n/app_text.dart';
import '../models/access_request.dart';
import '../models/barangay_profile.dart';
import '../services/api_service.dart';
import '../theme/app_theme.dart';
import '../widgets/brand_header.dart';
import '../widgets/language_toggle.dart';
import '../widgets/wave_background.dart';

/// Resident portal access request — faithful rebuild of SOE
/// `request_access.php`: hero card + EN/FIL toggle, 3 section cards
/// (Personal Information, Address in Barangay, Verification Requirement),
/// then a success state with the "What happens next" steps.
class RequestAccessScreen extends StatefulWidget {
  const RequestAccessScreen({super.key});

  @override
  State<RequestAccessScreen> createState() => _RequestAccessScreenState();
}

class _RequestAccessScreenState extends State<RequestAccessScreen> {
  final _api = ApiService();
  final _picker = ImagePicker();
  final _formKey = GlobalKey<FormState>();

  final _first = TextEditingController();
  final _middle = TextEditingController();
  final _last = TextEditingController();
  final _email = TextEditingController();
  final _contact = TextEditingController();

  DateTime? _birthdate;
  final _birthCtrl = TextEditingController(); // mm/dd/yyyy
  final _house = TextEditingController();
  final _building = TextEditingController();

  // Address comes from the admin-managed barangay profile, not hard-coded.
  BarangayProfile? _profile;
  List<String> _streets = const [];
  List<AreaOption> _areas = const [];
  String? _street;
  AreaOption? _area;
  bool _loadingAddr = true;

  // Store the picked image as bytes so preview + upload work on web AND mobile.
  XFile? _validId;
  XFile? _selfie;
  Uint8List? _validIdBytes;
  Uint8List? _selfieBytes;
  String? _validIdError;
  String? _selfieError;

  bool _submitting = false;
  bool _submitted = false;

  @override
  void initState() {
    super.initState();
    _loadAddress();
  }

  /// Pull the admin-configured barangay address + its streets & areas.
  Future<void> _loadAddress() async {
    final profile = await _api.fetchBarangayProfile();
    if (profile == null) {
      if (mounted) setState(() => _loadingAddr = false);
      return;
    }
    final streets = await _api.fetchStreets(profile.barangayCode);
    final areas = await _api.fetchAreas(profile.barangayCode);
    if (mounted) {
      setState(() {
        _profile = profile;
        _streets = streets;
        _areas = areas;
        _loadingAddr = false;
      });
    }
  }

  @override
  void dispose() {
    for (final c in [
      _first,
      _middle,
      _last,
      _email,
      _contact,
      _house,
      _building,
      _birthCtrl
    ]) {
      c.dispose();
    }
    _api.dispose();
    super.dispose();
  }

  Future<void> _pick(bool selfie) async {
    final x = await _picker.pickImage(
      source: selfie ? ImageSource.camera : ImageSource.gallery,
      imageQuality: 75,
    );
    if (x == null) return;
    final bytes = await x.readAsBytes();
    setState(() {
      if (selfie) {
        _selfie = x;
        _selfieBytes = bytes;
        _selfieError = null;
      } else {
        _validId = x;
        _validIdBytes = bytes;
        _validIdError = null;
      }
    });
  }

  Future<void> _pickBirthdate() async {
    final now = DateTime.now();
    final init = _parseMMDDYYYY(_birthCtrl.text) ?? DateTime(now.year - 18);
    final d = await showDatePicker(
      context: context,
      initialDate: init,
      firstDate: DateTime(1900),
      lastDate: now,
    );
    if (d != null) {
      setState(() {
        _birthdate = d;
        _birthCtrl.text = _fmtMMDDYYYY(d);
      });
    }
  }

  /// Parse "mm/dd/yyyy" → DateTime (null if invalid or in the future).
  DateTime? _parseMMDDYYYY(String s) {
    final m = RegExp(r'^(\d{2})/(\d{2})/(\d{4})$').firstMatch(s.trim());
    if (m == null) return null;
    final mm = int.parse(m.group(1)!);
    final dd = int.parse(m.group(2)!);
    final yy = int.parse(m.group(3)!);
    if (mm < 1 || mm > 12 || dd < 1 || dd > 31 || yy < 1900) return null;
    final d = DateTime(yy, mm, dd);
    if (d.month != mm || d.day != dd) return null; // e.g. 02/30
    if (d.isAfter(DateTime.now())) return null;
    return d;
  }

  /// Specific message: wrong format, future date, or a year before 1900.
  String? _birthError(String? v) {
    final s = (v ?? '').trim();
    final m = RegExp(r'^(\d{2})/(\d{2})/(\d{4})$').firstMatch(s);
    if (m == null) return tr.raUseMmDdYyyy;
    final mm = int.parse(m.group(1)!), dd = int.parse(m.group(2)!);
    final yy = int.parse(m.group(3)!);
    final d = DateTime(yy, mm, dd);
    if (mm < 1 || mm > 12 || d.month != mm || d.day != dd) {
      return tr.raUseMmDdYyyy; // e.g. 13/01 or 02/30
    }
    if (yy < 1900) return tr.raBirthTooOld;
    if (d.isAfter(DateTime.now())) return tr.raBirthFuture;
    return null;
  }

  String _fmtMMDDYYYY(DateTime d) =>
      '${d.month.toString().padLeft(2, '0')}/${d.day.toString().padLeft(2, '0')}/${d.year}';

  Future<void> _submit() async {
    setState(() {
      _validIdError = _validId == null ? tr.raPleaseUploadAValid : null;
      _selfieError = _selfie == null ? tr.raPleaseUploadASelfie : null;
    });
    final formOk = _formKey.currentState!.validate();
    _birthdate = _parseMMDDYYYY(_birthCtrl.text);
    if (_birthdate == null) {
      _snack(_birthError(_birthCtrl.text) ?? tr.raEnterAValidDate);
      return;
    }
    if (_profile == null) {
      _snack(tr.raBarangayAddressIsNot);
      return;
    }
    if (_street == null || _area == null) {
      _snack(tr.raPleaseSelectYourStreet);
      return;
    }
    if (!formOk || _validId == null || _selfie == null) return;

    setState(() => _submitting = true);
    // access_requests has no building column, so fold it into house_no.
    final building = _building.text.trim();
    final houseNo = building.isEmpty
        ? _house.text.trim()
        : '${_house.text.trim()}, $building';
    final request = AccessRequest(
      firstName: _first.text.trim(),
      middleName: _middle.text.trim(),
      lastName: _last.text.trim(),
      email: _email.text.trim(),
      contactNumber: _contact.text.trim(),
      birthdate: _birthdate,
      houseNo: houseNo,
      street: _street,
      purok: _area?.name,
    );
    final res = await _api.submitAccessRequest(
      request,
      validIdBytes: _validIdBytes,
      validIdName: _validId?.name,
      selfieBytes: _selfieBytes,
      selfieName: _selfie?.name,
    );
    if (!mounted) return;
    setState(() => _submitting = false);
    if (res.ok) {
      setState(() => _submitted = true);
    } else {
      _snack(res.message);
    }
  }

  void _snack(String m) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: WaveBackground(
        child: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(16, 20, 16, 80),
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 640),
                child: _submitted ? _successView() : _formView(),
              ),
            ),
          ),
        ),
      ),
    );
  }

  // ── FORM ──────────────────────────────────────────────────────────────
  Widget _formView() {
    return Form(
      key: _formKey,
      child: Column(
        children: [
          _heroCard(),
          const SizedBox(height: 16),
          _infoBanner(),
          const SizedBox(height: 16),
          _personalSection(),
          const SizedBox(height: 16),
          _addressSection(),
          const SizedBox(height: 16),
          _verificationSection(),
          const SizedBox(height: 16),
          PrimaryButton(
            label: tr.raSubmitRegistration,
            icon: Icons.send,
            loading: _submitting,
            onPressed: _submit,
          ),
          const SizedBox(height: 12),
          TextButton.icon(
            onPressed: () => Navigator.of(context).pop(),
            icon: const Icon(Icons.arrow_back, size: 16),
            label: Text(tr.backToLogin),
          ),
        ],
      ),
    );
  }

  Widget _heroCard() {
    return GlassCard(
      padding: const EdgeInsets.all(24),
      child: Column(
        children: [
          const Align(
            alignment: Alignment.centerRight,
            child: LanguageToggle(),
          ),
          const BrandHeader(showBadge: false),
          const SizedBox(height: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                  colors: [AppColors.primary, AppColors.primaryDark]),
              borderRadius: BorderRadius.circular(50),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.how_to_reg, size: 16, color: Colors.white),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(tr.raRequestPortalAccess,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 0.5)),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _infoBanner() {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.infoBg,
        border: Border.all(color: AppColors.infoBorder),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.info_outline, color: AppColors.accent, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr.raFillOutTheForm,
                    style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: AppColors.infoText)),
                const SizedBox(height: 2),
                Text(tr.raUploadAValidId,
                    style: TextStyle(fontSize: 12, color: AppColors.infoText2)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _personalSection() {
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _sectionHead(Icons.person, tr.raPersonalInformation, '1'),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                  child: _input(tr.raFirstName, _first,
                      hint: 'Juan',
                      required: true,
                      inputFormatters: [_TitleCaseFormatter()])),
              const SizedBox(width: 12),
              Expanded(
                  child: _input(tr.raMiddleName, _middle,
                      hint: 'Santos',
                      inputFormatters: [_TitleCaseFormatter()])),
            ],
          ),
          const SizedBox(height: 12),
          _input(tr.raLastName, _last,
              hint: 'Dela Cruz',
              required: true,
              inputFormatters: [_TitleCaseFormatter()]),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _input(tr.raEmailAddress, _email,
                    hint: 'juan@email.com',
                    icon: Icons.mail_outline,
                    required: true,
                    keyboardType: TextInputType.emailAddress, validator: (v) {
                  if (v == null || v.trim().isEmpty) {
                    return tr.required;
                  }
                  if (!RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$')
                      .hasMatch(v.trim())) {
                    return tr.raInvalidEmail;
                  }
                  return null;
                }),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _input(tr.raContactNumber, _contact,
                    hint: '09XXXXXXXXX',
                    icon: Icons.call,
                    required: true,
                    keyboardType: TextInputType.phone,
                    inputFormatters: [
                      FilteringTextInputFormatter.digitsOnly,
                      LengthLimitingTextInputFormatter(11),
                    ], validator: (v) {
                  if (v == null || v.trim().isEmpty) {
                    return tr.required;
                  }
                  if (!RegExp(r'^09\d{9}$').hasMatch(v.trim())) {
                    return tr.raFormat09xxxxxxxxx;
                  }
                  return null;
                }),
              ),
            ],
          ),
          const SizedBox(height: 12),
          FieldLabel(tr.raDateOfBirth, required: true),
          TextFormField(
            controller: _birthCtrl,
            keyboardType: TextInputType.datetime,
            inputFormatters: [_DateInputFormatter()],
            decoration: AppTheme.field(
              'mm/dd/yyyy',
              icon: Icons.cake_outlined,
              suffix: IconButton(
                icon: Icon(Icons.calendar_today,
                    size: 20, color: AppColors.slate400),
                tooltip: tr.raPickADate,
                onPressed: _pickBirthdate,
              ),
            ),
            autovalidateMode: AutovalidateMode.onUserInteraction,
            validator: _birthError,
          ),
        ],
      ),
    );
  }

  Widget _addressSection() {
    final p = _profile;
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header like admin residents.php: dark rounded icon + title.
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [AppColors.primary, AppColors.primaryDark],
                  ),
                  borderRadius: BorderRadius.circular(12),
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.primary.withValues(alpha: 0.25),
                      blurRadius: 10,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                child: const Icon(Icons.location_on,
                    size: 20, color: Colors.white),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(tr.raAddressInformation,
                    style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w800,
                        color: AppColors.slate800,
                        letterSpacing: 0.5)),
              ),
              const SizedBox(width: 8),
              Text(tr.stepOf(2, 3),
                  style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w600,
                      color: AppColors.slate400)),
            ],
          ),
          const SizedBox(height: 14),
          if (_loadingAddr)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 8),
              child: LinearProgressIndicator(),
            )
          else if (p == null)
            _addrWarning()
          else ...[
            _defaultAddressNote(p),
            const SizedBox(height: 14),
            // Grouped read-only panel (Region/Province/City/Barangay).
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.surfaceAlt,
                border: Border.all(color: AppColors.border),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Column(
                children: [
                  Row(
                    children: [
                      Expanded(child: _addrReadonly(tr.region, p.regionName)),
                      const SizedBox(width: 12),
                      Expanded(
                          child: _addrReadonly(tr.raProvince, p.provinceName)),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                          child: _addrReadonly(
                              tr.raCityMunicipality, p.municipalityName)),
                      const SizedBox(width: 12),
                      Expanded(
                          child: _addrReadonly('Barangay', p.barangayName)),
                    ],
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 16),
          // Editable fields the resident fills in.
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                  child: _input(tr.raHouseLotUnitNumber, _house,
                      hint: tr.raHouseHint,
                      required: true,
                      inputFormatters: [_TitleCaseFormatter()])),
              const SizedBox(width: 12),
              Expanded(
                  child: _input(tr.raBuildingName, _building,
                      hint: tr.optional,
                      inputFormatters: [_TitleCaseFormatter()])),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: _dropdownField<String>(
                  label: tr.raStreet,
                  required: true,
                  value: _street,
                  hint: _streets.isEmpty
                      ? tr.raNoStreetsConfigured
                      : tr.raSelectStreet,
                  items: _streets
                      .map((s) => DropdownMenuItem(value: s, child: Text(s)))
                      .toList(),
                  onChanged: _streets.isEmpty
                      ? null
                      : (v) => setState(() => _street = v),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _dropdownField<AreaOption>(
                  label: tr.raSubdivisionVillageSitioPurok,
                  required: true,
                  value: _area,
                  hint:
                      _areas.isEmpty ? tr.raNoAreasConfigured : tr.raSelectArea,
                  items: _areas
                      .map((a) =>
                          DropdownMenuItem(value: a, child: Text(a.label)))
                      .toList(),
                  onChanged:
                      _areas.isEmpty ? null : (v) => setState(() => _area = v),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          SizedBox(
            width: 200,
            child: _addrReadonly(tr.zipCode, p?.zipCode ?? '—', boxed: true),
          ),
        ],
      ),
    );
  }

  /// Compact label + value used inside the grouped panel (white box).
  Widget _addrReadonly(String label, String value, {bool boxed = true}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label.toUpperCase(),
            style: TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w800,
                color: AppColors.slate400,
                letterSpacing: 0.8)),
        const SizedBox(height: 4),
        Container(
          width: double.infinity,
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: BoxDecoration(
            color: boxed ? AppColors.surface : Colors.transparent,
            border: Border.all(color: AppColors.border),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Text(value.isEmpty ? '—' : value,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                  fontSize: 13.5,
                  fontWeight: FontWeight.w600,
                  color: AppColors.slate800)),
        ),
      ],
    );
  }

  Widget _dropdownField<T>({
    required String label,
    required T? value,
    required String hint,
    required List<DropdownMenuItem<T>> items,
    required ValueChanged<T?>? onChanged,
    bool required = false,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        FieldLabel(label, required: required),
        DropdownButtonFormField<T>(
          value: value,
          isExpanded: true,
          decoration: AppTheme.field(hint),
          items: items,
          onChanged: onChanged,
        ),
      ],
    );
  }

  Widget _defaultAddressNote(BarangayProfile p) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.successBg,
        border: Border.all(color: AppColors.successBorder),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
          '${tr.raDefaultAddress}: '
          '${p.regionName} · ${p.provinceName} · ${p.municipalityName} · ${p.barangayName}. '
          '${tr.raYouOnlyNeedTo}',
          style: TextStyle(
              fontSize: 11.5,
              fontWeight: FontWeight.w600,
              color: AppColors.successText)),
    );
  }

  Widget _addrWarning() {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.warnBg,
        border: Border.all(color: AppColors.warnBorder),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.warning_amber, size: 18, color: AppColors.warnText),
          const SizedBox(width: 8),
          Expanded(
            child: Text(tr.raTheBarangayDefaultAddress,
                style: TextStyle(fontSize: 11.5, color: AppColors.warnText)),
          ),
        ],
      ),
    );
  }

  Widget _verificationSection() {
    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _sectionHead(Icons.verified_user, tr.raVerificationRequirement, '3'),
          const SizedBox(height: 16),
          FieldLabel(tr.raValidIdUpload, required: true),
          _uploadZone(
            hasFile: _validId != null,
            fileName: _validId?.name,
            bytes: _validIdBytes,
            onTap: () => _pick(false),
            icon: Icons.upload_file,
            title: tr.raClickToUploadYour,
            sub: tr.raAcceptedJpgPngMax,
            error: _validIdError,
          ),
          const SizedBox(height: 6),
          Text(tr.raValidIdExamples,
              style: TextStyle(fontSize: 10, color: AppColors.slate400)),
          const SizedBox(height: 16),
          FieldLabel(tr.raSelfieHoldingYourId, required: true),
          _uploadZone(
            hasFile: _selfie != null,
            fileName: _selfie?.name,
            bytes: _selfieBytes,
            onTap: () => _pick(true),
            icon: Icons.add_a_photo,
            title: tr.raClickToUploadYour2,
            sub: tr.raJpgOrPngFace,
            error: _selfieError,
            preview: true,
          ),
          const SizedBox(height: 20),
          _whatNext(),
        ],
      ),
    );
  }

  Widget _whatNext() {
    final steps = tr.raNextSteps;
    return Container(
      padding: const EdgeInsets.only(top: 16),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: AppColors.muted)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(Icons.timeline, size: 16, color: AppColors.primary),
              const SizedBox(width: 6),
              Text(tr.raWhatHappensNext,
                  style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w800,
                      color: AppColors.primary,
                      letterSpacing: 1.5)),
            ],
          ),
          const SizedBox(height: 12),
          ...List.generate(steps.length, (i) {
            final last = i == steps.length - 1;
            return Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    width: 26,
                    height: 26,
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: last
                            ? const [Color(0xFF16A34A), Color(0xFF15803D)]
                            : [AppColors.primary, AppColors.primaryDark],
                      ),
                      shape: BoxShape.circle,
                    ),
                    child: Text('${i + 1}',
                        style: const TextStyle(
                            color: Colors.white,
                            fontSize: 11,
                            fontWeight: FontWeight.w800)),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(steps[i][0],
                            style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                                color: AppColors.slate800)),
                        Text(steps[i][1],
                            style: TextStyle(
                                fontSize: 11, color: AppColors.slate400)),
                      ],
                    ),
                  ),
                ],
              ),
            );
          }),
        ],
      ),
    );
  }

  // ── SUCCESS ───────────────────────────────────────────────────────────
  Widget _successView() {
    final steps = tr.raSuccessSteps;
    return GlassCard(
      padding: const EdgeInsets.all(28),
      child: Column(
        children: [
          BrandHeader(
              badgeText: tr.raRequestSubmitted, badgeIcon: Icons.task_alt),
          const SizedBox(height: 20),
          Container(
            width: 80,
            height: 80,
            decoration: BoxDecoration(
              color: AppColors.successBg,
              shape: BoxShape.circle,
              border: Border.all(color: AppColors.successBorder, width: 2),
            ),
            child: const Icon(Icons.how_to_reg,
                size: 44, color: AppColors.success),
          ),
          const SizedBox(height: 16),
          Text(tr.raYourRequestHasBeen,
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w700,
                  color: AppColors.slate800)),
          const SizedBox(height: 8),
          Text(tr.raTheBarangayStaffWill,
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 13, color: AppColors.slate500)),
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: AppColors.infoBg,
              border: Border.all(color: AppColors.infoBorder),
              borderRadius: BorderRadius.circular(16),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr.raWhatHappensNext2,
                    style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: AppColors.infoText)),
                const SizedBox(height: 8),
                ...steps.map((s) => Padding(
                      padding: const EdgeInsets.only(bottom: 6),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Icon(Icons.check,
                              size: 14, color: AppColors.accent),
                          const SizedBox(width: 6),
                          Expanded(
                            child: Text(s,
                                style: TextStyle(
                                    fontSize: 12, color: AppColors.infoText2)),
                          ),
                        ],
                      ),
                    )),
              ],
            ),
          ),
          const SizedBox(height: 16),
          TextButton.icon(
            onPressed: () => Navigator.of(context).pop(),
            icon: const Icon(Icons.arrow_back, size: 16),
            label: Text(tr.backToLogin),
          ),
        ],
      ),
    );
  }

  // ── small builders ──────────────────────────────────────────────────
  Widget _sectionHead(IconData icon, String label, String step) {
    return Row(
      children: [
        Flexible(child: SectionBadge(icon: icon, label: label)),
        const SizedBox(width: 8),
        const Spacer(),
        Text(tr.stepOf(int.parse(step), 3),
            style: TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w600,
                color: AppColors.slate400)),
      ],
    );
  }

  Widget _input(
    String label,
    TextEditingController c, {
    String? hint,
    IconData? icon,
    bool required = false,
    TextInputType? keyboardType,
    List<TextInputFormatter>? inputFormatters,
    String? Function(String?)? validator,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        FieldLabel(label, required: required),
        TextFormField(
          controller: c,
          keyboardType: keyboardType,
          inputFormatters: inputFormatters,
          decoration: AppTheme.field(hint ?? '', icon: icon),
          validator: validator ??
              (required
                  ? (v) => (v == null || v.trim().isEmpty) ? tr.required : null
                  : null),
        ),
      ],
    );
  }

  Widget _uploadZone({
    required bool hasFile,
    String? fileName,
    Uint8List? bytes,
    required VoidCallback onTap,
    required IconData icon,
    required String title,
    required String sub,
    String? error,
    bool preview = false,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        GestureDetector(
          onTap: onTap,
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(vertical: 24, horizontal: 20),
            decoration: BoxDecoration(
              color: hasFile ? AppColors.successBg : AppColors.surfaceAlt,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(
                color: hasFile ? AppColors.success : AppColors.slate200,
                width: 2,
                style: BorderStyle.solid,
              ),
            ),
            child: Column(
              children: [
                if (hasFile && preview && bytes != null) ...[
                  ClipRRect(
                    borderRadius: BorderRadius.circular(12),
                    child: Image.memory(bytes,
                        height: 150, fit: BoxFit.cover, width: double.infinity),
                  ),
                  const SizedBox(height: 8),
                ],
                Icon(hasFile ? Icons.task_alt : icon,
                    size: 34,
                    color: hasFile ? AppColors.success : AppColors.primary),
                const SizedBox(height: 6),
                Text(hasFile ? (fileName ?? tr.raSelected) : title,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: hasFile
                            ? AppColors.successText
                            : AppColors.primary)),
                const SizedBox(height: 2),
                Text(hasFile ? tr.raTapToChange : sub,
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 11, color: AppColors.slate400)),
              ],
            ),
          ),
        ),
        if (error != null)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(error,
                style: const TextStyle(
                    fontSize: 11,
                    color: AppColors.danger,
                    fontWeight: FontWeight.w600)),
          ),
      ],
    );
  }
}

/// Capitalises the first letter of every word as the user types
/// ("rendell" → "Rendell", "dela cruz" → "Dela Cruz"). Length is unchanged,
/// so the caret position is preserved.
class _TitleCaseFormatter extends TextInputFormatter {
  static final _letter = RegExp(r'[a-zA-ZñÑ]');

  @override
  TextEditingValue formatEditUpdate(
      TextEditingValue oldValue, TextEditingValue newValue) {
    final text = newValue.text;
    final buf = StringBuffer();
    bool capNext = true;
    for (final ch in text.split('')) {
      if (capNext && _letter.hasMatch(ch)) {
        buf.write(ch.toUpperCase());
        capNext = false;
      } else {
        buf.write(ch);
        capNext = (ch == ' ' || ch == '-' || ch == "'");
      }
    }
    return TextEditingValue(
        text: buf.toString(), selection: newValue.selection);
  }
}

/// Formats digits into a mm/dd/yyyy mask as the user types.
class _DateInputFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
      TextEditingValue oldValue, TextEditingValue newValue) {
    var digits = newValue.text.replaceAll(RegExp(r'\D'), '');
    if (digits.length > 8) digits = digits.substring(0, 8);
    final buf = StringBuffer();
    for (int i = 0; i < digits.length; i++) {
      if (i == 2 || i == 4) buf.write('/');
      buf.write(digits[i]);
    }
    final text = buf.toString();
    return TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }
}
