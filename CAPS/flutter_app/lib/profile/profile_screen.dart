import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'profile_api.dart';
import 'profile_avatar.dart';
import 'profile_model.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// MY PROFILE — ported from SOE `user/profile.php`.
///   • Profile picture: take a photo / choose from gallery / remove
///   • Personal, contact, address, household, work & education, sectors &
///     benefits and account details — VIEW ONLY (corrections go through the
///     barangay for now)
///
/// Talks to `user/backend/profile.php`. Pops `true` when the photo changed so
/// the dashboard can refresh its avatar. Kept in its own `profile/` folder.
/// ─────────────────────────────────────────────────────────────────────────
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _api = ProfileApi();
  final _picker = ImagePicker();

  ResidentProfile? _profile;
  String? _error;
  bool _loading = true;
  bool _uploading = false;
  bool _photoChanged = false;

  static const _maxBytes = 5 * 1024 * 1024;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _api.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final res = await _api.get(widget.resident.residentId);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res.ok) {
        _profile = res.data;
        _error = null;
      } else {
        _error = res.message;
      }
    });
  }

  // ── Photo ─────────────────────────────────────────────────────────────
  void _photoOptions() {
    final hasPhoto = (_profile?.photoUrl ?? '').isNotEmpty;
    showModalBottomSheet(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: Text(tr.takePhoto),
              onTap: () {
                Navigator.pop(ctx);
                _pick(ImageSource.camera);
              },
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: Text(tr.chooseFromGallery),
              onTap: () {
                Navigator.pop(ctx);
                _pick(ImageSource.gallery);
              },
            ),
            if (hasPhoto)
              ListTile(
                leading:
                    const Icon(Icons.delete_outline, color: AppColors.danger),
                title: Text(tr.removePhoto,
                    style: const TextStyle(color: AppColors.danger)),
                onTap: () {
                  Navigator.pop(ctx);
                  _remove();
                },
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  Future<void> _pick(ImageSource source) async {
    final x = await _picker.pickImage(
      source: source,
      imageQuality: 80,
      maxWidth: 1024,
      maxHeight: 1024,
      preferredCameraDevice: CameraDevice.front,
    );
    if (x == null) return;
    final bytes = await x.readAsBytes();
    if (!mounted) return;
    if (bytes.length > _maxBytes) {
      _snack(tr.photoTooLarge, error: true);
      return;
    }
    setState(() => _uploading = true);
    final res =
        await _api.uploadPhoto(widget.resident.residentId, bytes, x.name);
    if (!mounted) return;
    setState(() {
      _uploading = false;
      if (res.ok) {
        _profile = _profile?.withPhoto(res.data);
        _photoChanged = true;
      }
    });
    _snack(res.message, error: !res.ok);
  }

  Future<void> _remove() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr.removePhotoTitle),
        content: Text(tr.removePhotoBody),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(tr.cancel)),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(tr.remove)),
        ],
      ),
    );
    if (ok != true) return;
    setState(() => _uploading = true);
    final res = await _api.removePhoto(widget.resident.residentId);
    if (!mounted) return;
    setState(() {
      _uploading = false;
      if (res.ok) {
        _profile = _profile?.withPhoto(null);
        _photoChanged = true;
      }
    });
    _snack(res.message, error: !res.ok);
  }

  void _snack(String m, {bool error = false}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(m),
        backgroundColor: error ? AppColors.danger : null,
      ));

  // ── UI ────────────────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Navigator.of(context).pop(_photoChanged);
      },
      child: Scaffold(
        backgroundColor: AppColors.scaffold,
        appBar: AppBar(
          backgroundColor: AppColors.appBar,
          foregroundColor: Colors.white,
          elevation: 0,
          title: Text(tr.myProfile,
              style:
                  const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
          actions: [
            IconButton(
              tooltip: tr.refresh,
              onPressed: () {
                setState(() => _loading = true);
                _load();
              },
              icon: const Icon(Icons.refresh),
            ),
          ],
        ),
        body: _loading
            ? Center(child: CircularProgressIndicator(color: AppColors.primary))
            : RefreshIndicator(
                color: AppColors.primary,
                onRefresh: _load,
                child: Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 560),
                    child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
                      children: _profile == null
                          ? [_errorBox(_error ?? tr.profileLoadFailed)]
                          : _content(_profile!),
                    ),
                  ),
                ),
              ),
      ),
    );
  }

  List<Widget> _content(ResidentProfile p) {
    const dash = '—';
    String v(String? s) => (s == null || s.isEmpty) ? dash : tr.valueLabel(s);
    String yn(bool? b) => b == null ? dash : (b ? tr.yes : tr.no);
    return [
      _header(p),
      const SizedBox(height: 14),
      _viewOnlyNote(),
      const SizedBox(height: 20),
      _section(tr.personalInformation, Icons.person_outline, [
        (tr.fullName, p.fullName),
        (tr.sex, v(p.sex)),
        (tr.birthDate, p.birthDate == null ? dash : _date(p.birthDate!)),
        (tr.age, p.age == null ? dash : '${p.age}'),
        (tr.birthPlace, v(p.birthPlace)),
        (tr.civilStatus, v(p.civilStatus)),
        (tr.religion, v(p.religion)),
        (tr.nationality, v(p.nationality)),
      ]),
      _section(tr.contactInformation, Icons.contact_phone_outlined, [
        (tr.emailAddress, v(p.email)),
        (tr.contactNumber, v(p.contactNumber)),
      ]),
      _section(tr.addressLabel, Icons.home_outlined, [
        (tr.houseAndStreet, p.address.isEmpty ? dash : p.address),
        (tr.purokArea, v(p.purok)),
      ]),
      _section(tr.household, Icons.groups_outlined, [
        (tr.headOfFamily, yn(p.isHead)),
        (tr.relationshipToHead, v(p.relationship)),
        if (p.householdHead != null) (tr.householdHead, p.householdHead!),
      ]),
      _section(tr.workAndEducation, Icons.work_outline, [
        (tr.employment, v(p.employment)),
        (tr.education, v(p.education)),
        (
          tr.householdIncome,
          p.householdIncome == null ? dash : _peso(p.householdIncome!)
        ),
      ]),
      _flagsSection(p),
      _section(tr.account, Icons.verified_user_outlined, [
        (tr.residentCode, v(p.residentCode)),
        (tr.memberSince, p.memberSince == null ? dash : _date(p.memberSince!)),
      ]),
    ];
  }

  Widget _header(ResidentProfile p) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.heading2, AppColors.primary, AppColors.navy],
        ),
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(
              color: AppColors.primary.withValues(alpha: .30),
              blurRadius: 24,
              offset: const Offset(0, 10)),
        ],
      ),
      child: Column(
        children: [
          Stack(
            clipBehavior: Clip.none,
            children: [
              ProfileAvatar(
                initials: p.initials,
                photoUrl: p.photoUrl,
                size: 104,
                onGradient: true,
              ),
              if (_uploading)
                Positioned.fill(
                  child: Container(
                    decoration: const BoxDecoration(
                        color: Colors.black38, shape: BoxShape.circle),
                    child: const Center(
                      child: SizedBox(
                        width: 28,
                        height: 28,
                        child: CircularProgressIndicator(
                            strokeWidth: 2.5, color: Colors.white),
                      ),
                    ),
                  ),
                ),
              Positioned(
                right: -2,
                bottom: -2,
                child: Material(
                  color: Colors.white,
                  shape: const CircleBorder(),
                  elevation: 3,
                  child: InkWell(
                    customBorder: const CircleBorder(),
                    onTap: _uploading ? null : _photoOptions,
                    child: Padding(
                      padding: const EdgeInsets.all(8),
                      child: Icon(Icons.photo_camera,
                          size: 20,
                          color: AppColors.isDark
                              ? AppColors.primaryDark
                              : AppColors.primary,
                          semanticLabel: tr.changePhoto),
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Text(p.fullName,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  color: Colors.white,
                  fontSize: 20,
                  fontWeight: FontWeight.w900,
                  height: 1.15)),
          if (p.residentCode != null) ...[
            const SizedBox(height: 4),
            Text(p.residentCode!,
                style: TextStyle(
                    color: Colors.white.withValues(alpha: .75),
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    letterSpacing: .5)),
          ],
          const SizedBox(height: 12),
          TextButton.icon(
            onPressed: _uploading ? null : _photoOptions,
            style: TextButton.styleFrom(
              foregroundColor: Colors.white,
              backgroundColor: Colors.white.withValues(alpha: .15),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(50)),
              padding: const EdgeInsets.symmetric(horizontal: 16),
            ),
            icon: const Icon(Icons.photo_camera_outlined, size: 16),
            label: Text(tr.changePhoto,
                style:
                    const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }

  Widget _viewOnlyNote() => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.infoBg,
          border: Border.all(color: AppColors.infoBorder),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.info_outline, size: 18, color: AppColors.infoText),
            const SizedBox(width: 10),
            Expanded(
              child: Text(tr.profileViewOnly,
                  style: TextStyle(
                      fontSize: 12, height: 1.4, color: AppColors.infoText)),
            ),
          ],
        ),
      );

  Widget _section(String title, IconData icon, List<(String, String)> rows) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: _card(
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _sectionTitle(title, icon),
            const SizedBox(height: 6),
            for (var i = 0; i < rows.length; i++) ...[
              if (i > 0) Divider(height: 1, color: AppColors.border),
              _row(rows[i].$1, rows[i].$2),
            ],
          ],
        ),
      ),
    );
  }

  Widget _flagsSection(ResidentProfile p) {
    final flags = <(String, bool?)>[
      (tr.registeredVoter, p.isVoter),
      (p.pwdClass == null ? tr.pwd : '${tr.pwd} · ${p.pwdClass}', p.isPwd),
      (tr.seniorCitizen, p.isSenior),
      (tr.soloParent, p.isSoloParent),
      ('PhilHealth', p.hasPhilhealth),
      ('SSS / GSIS', p.hasSssGsis),
      ('4Ps', p.has4ps),
    ];
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: _card(
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _sectionTitle(
                tr.sectorsAndBenefits, Icons.volunteer_activism_outlined),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final f in flags)
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                    decoration: BoxDecoration(
                      color: f.$2 == true
                          ? AppColors.successBg
                          : AppColors.surfaceAlt,
                      border: Border.all(
                          color: f.$2 == true
                              ? AppColors.successBorder
                              : AppColors.border),
                      borderRadius: BorderRadius.circular(50),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                            f.$2 == true
                                ? Icons.check_circle
                                : Icons.remove_circle_outline,
                            size: 14,
                            color: f.$2 == true
                                ? AppColors.successText
                                : AppColors.slate400),
                        const SizedBox(width: 6),
                        Flexible(
                          child: Text(f.$1,
                              style: TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w700,
                                  color: f.$2 == true
                                      ? AppColors.successText
                                      : AppColors.slate500)),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _sectionTitle(String title, IconData icon) => Row(
        children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: .12),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, size: 18, color: AppColors.primary),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(title.toUpperCase(),
                style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w900,
                    letterSpacing: .8,
                    color: AppColors.heading2)),
          ),
        ],
      );

  Widget _row(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              flex: 2,
              child: Text(label,
                  style: TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: AppColors.slate500)),
            ),
            const SizedBox(width: 12),
            Expanded(
              flex: 3,
              child: Text(value,
                  textAlign: TextAlign.right,
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w800,
                      color: AppColors.slate800)),
            ),
          ],
        ),
      );

  Widget _card(Widget child) => Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: AppColors.border),
          boxShadow: [
            BoxShadow(
                color: AppColors.primary.withValues(alpha: .06),
                blurRadius: 18,
                offset: const Offset(0, 6)),
          ],
        ),
        child: child,
      );

  Widget _errorBox(String msg) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.dangerBg,
          border: Border.all(color: AppColors.dangerBorder),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Text(msg,
            style: TextStyle(
                color: AppColors.dangerText, fontWeight: FontWeight.w700)),
      );

  String _date(DateTime d) =>
      '${tr.monthsShort[d.month - 1]} ${d.day}, ${d.year}';

  String _peso(double v) {
    final s = v.toStringAsFixed(2);
    final parts = s.split('.');
    final whole = parts[0]
        .replaceAllMapped(RegExp(r'(\d)(?=(\d{3})+$)'), (m) => '${m[1]},');
    return '₱$whole.${parts[1]}';
  }
}
