import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../profile/profile_avatar.dart';
import '../theme/app_theme.dart';
import 'household_api.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// MY HOUSEHOLD — ported from SOE `user/household.php`.
///   • Your role: Household Head, or your relationship to the head
///   • Household info (ID, address, purok, income, registered)
///   • Summary (total, male, female, seniors, minors, PWD)
///   • Head + members; tap a person for their details
///
/// VIEW ONLY — the barangay manages household records. Talks to
/// `user/backend/household.php`. Kept in its own `household/` folder.
/// ─────────────────────────────────────────────────────────────────────────
class HouseholdScreen extends StatefulWidget {
  const HouseholdScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<HouseholdScreen> createState() => _HouseholdScreenState();
}

class _HouseholdScreenState extends State<HouseholdScreen> {
  final _api = HouseholdApi();

  Household? _data;
  String? _error;
  bool _loading = true;

  static const _headColor = Color(0xFFF59E0B);

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
        _data = res.data;
        _error = null;
      } else {
        _error = res.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: AppBar(
        backgroundColor: AppColors.appBar,
        foregroundColor: Colors.white,
        elevation: 0,
        title: Text(tr.myHousehold,
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
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
                    children: _data == null
                        ? [_errorBox(_error ?? tr.householdLoadFailed)]
                        : _content(_data!),
                  ),
                ),
              ),
            ),
    );
  }

  List<Widget> _content(Household h) {
    if (!h.isLinked) {
      return [_hero(h), const SizedBox(height: 16), _notLinked()];
    }
    return [
      _hero(h),
      const SizedBox(height: 14),
      _note(),
      const SizedBox(height: 16),
      _infoCard(h.info!),
      _statsCard(h.stats),
      _membersCard(h.members),
    ];
  }

  // ── Hero: who am I in this household ──────────────────────────────────
  Widget _hero(Household h) {
    final String? subtitle = h.isHead
        ? tr.youAreHead
        : (h.isLinked ? tr.youAreMemberOf(h.info!.headName) : null);
    final role = h.isHead
        ? tr.householdHeadRole
        : (h.isLinked && h.relationship != null
            ? tr.relationLabel(h.relationship!)
            : null);

    return Container(
      padding: const EdgeInsets.all(20),
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
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: .18),
                borderRadius: BorderRadius.circular(14),
              ),
              child: const Icon(Icons.home_rounded, color: Colors.white),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(tr.myHousehold,
                  style: const TextStyle(
                      color: Colors.white,
                      fontSize: 20,
                      fontWeight: FontWeight.w900)),
            ),
          ]),
          if (subtitle != null) ...[
            const SizedBox(height: 12),
            Text(subtitle,
                style: TextStyle(
                    color: Colors.white.withValues(alpha: .88),
                    fontSize: 13.5,
                    height: 1.4,
                    fontWeight: FontWeight.w600)),
          ],
          if (role != null) ...[
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.fromLTRB(10, 8, 14, 8),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: .15),
                border: Border.all(color: Colors.white.withValues(alpha: .25)),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(h.isHead ? Icons.star_rounded : Icons.person_rounded,
                    color: h.isHead ? const Color(0xFFFCD34D) : Colors.white,
                    size: 22),
                const SizedBox(width: 8),
                Flexible(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(tr.yourRole.toUpperCase(),
                          style: TextStyle(
                              color: Colors.white.withValues(alpha: .7),
                              fontSize: 9.5,
                              letterSpacing: .8,
                              fontWeight: FontWeight.w800)),
                      Text(role,
                          style: const TextStyle(
                              color: Colors.white,
                              fontSize: 14,
                              fontWeight: FontWeight.w900)),
                    ],
                  ),
                ),
              ]),
            ),
          ],
        ],
      ),
    );
  }

  Widget _notLinked() => _card(
        Column(children: [
          const SizedBox(height: 8),
          Icon(Icons.home_work_outlined, size: 52, color: AppColors.slate400),
          const SizedBox(height: 12),
          Text(tr.noHouseholdTitle,
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w900,
                  color: AppColors.slate800)),
          const SizedBox(height: 8),
          Text(tr.noHouseholdBody,
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 13, height: 1.45, color: AppColors.slate500)),
          const SizedBox(height: 8),
        ]),
      );

  Widget _note() => Container(
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
              child: Text(tr.householdViewOnly,
                  style: TextStyle(
                      fontSize: 12, height: 1.4, color: AppColors.infoText)),
            ),
          ],
        ),
      );

  // ── Household info ────────────────────────────────────────────────────
  Widget _infoCard(HouseholdInfo i) {
    const dash = '—';
    final income = i.monthlyIncome == null
        ? dash
        : '${_peso(i.monthlyIncome!)}'
            '${i.incomeClass == null ? '' : '\n${tr.incomeClass(i.incomeClass!)}'}';
    return _section(tr.householdInfo, Icons.home_outlined, [
      (tr.householdId, i.householdId ?? tr.notYetAssigned),
      (tr.householdHead, i.headName.isEmpty ? dash : i.headName),
      (tr.houseAndStreet, i.address.isEmpty ? dash : i.address),
      (tr.purokArea, i.purok ?? dash),
      if (i.houseType != null) (tr.houseType, tr.valueLabel(i.houseType!)),
      if (i.tenure != null) (tr.tenureStatus, tr.valueLabel(i.tenure!)),
      (tr.monthlyIncome, income),
      (
        tr.householdSurvey,
        i.surveyOnFile ? tr.surveyOnFile : tr.surveyNotOnFile
      ),
      (tr.registeredOn, i.registered == null ? dash : _date(i.registered!)),
    ]);
  }

  // ── Summary ───────────────────────────────────────────────────────────
  Widget _statsCard(HouseholdStats s) {
    final items = <(String, int, IconData, Color)>[
      (tr.statTotal, s.total, Icons.groups_rounded, AppColors.primary),
      (tr.statMale, s.male, Icons.male_rounded, const Color(0xFF0EA5E9)),
      (tr.statFemale, s.female, Icons.female_rounded, const Color(0xFFEC4899)),
      (tr.statSeniors, s.seniors, Icons.elderly_rounded, _headColor),
      (
        tr.statMinors,
        s.minors,
        Icons.child_care_rounded,
        const Color(0xFF8B5CF6)
      ),
      (tr.pwd, s.pwd, Icons.accessible_rounded, const Color(0xFF16A34A)),
    ];
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: _card(
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _sectionTitle(tr.householdSummary, Icons.bar_chart_rounded),
            const SizedBox(height: 14),
            LayoutBuilder(builder: (context, c) {
              const gap = 10.0;
              final w = (c.maxWidth - gap * 2) / 3;
              return Wrap(
                spacing: gap,
                runSpacing: gap,
                children: [
                  for (final it in items)
                    SizedBox(
                      width: w,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                            vertical: 12, horizontal: 6),
                        decoration: BoxDecoration(
                          color: it.$4.withValues(alpha: .10),
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: Column(children: [
                          Icon(it.$3, size: 18, color: it.$4),
                          const SizedBox(height: 4),
                          Text('${it.$2}',
                              style: TextStyle(
                                  fontSize: 20,
                                  fontWeight: FontWeight.w900,
                                  color: it.$4)),
                          Text(it.$1,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.slate500)),
                        ]),
                      ),
                    ),
                ],
              );
            }),
          ],
        ),
      ),
    );
  }

  // ── Members ───────────────────────────────────────────────────────────
  Widget _membersCard(List<HouseholdMember> members) => Padding(
        padding: const EdgeInsets.only(bottom: 16),
        child: _card(
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Expanded(
                    child: _sectionTitle(
                        tr.householdMembers, Icons.groups_outlined)),
                Text(tr.membersCount(members.length),
                    style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: AppColors.slate500)),
              ]),
              const SizedBox(height: 8),
              for (var i = 0; i < members.length; i++) ...[
                if (i > 0) Divider(height: 1, color: AppColors.border),
                _memberRow(members[i]),
              ],
            ],
          ),
        ),
      );

  Widget _memberRow(HouseholdMember m) {
    final sub = [
      if (m.sex != null) tr.valueLabel(m.sex!),
      if (m.age != null) tr.yearsOld(m.age!),
    ].join(' · ');
    return Material(
      type: MaterialType.transparency,
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => _showMember(m),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 2),
          child: Row(
            children: [
              _avatar(m, 44),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(m.name,
                            style: TextStyle(
                                fontSize: 14,
                                fontWeight: FontWeight.w800,
                                color: AppColors.slate800)),
                        if (m.isMe) _pill(tr.you, AppColors.primary),
                      ],
                    ),
                    const SizedBox(height: 4),
                    Wrap(
                      spacing: 6,
                      runSpacing: 4,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        _relationPill(m),
                        if (sub.isNotEmpty)
                          Text(sub,
                              style: TextStyle(
                                  fontSize: 12, color: AppColors.slate500)),
                        if (m.isSenior) _pill(tr.senior, _headColor),
                        if (m.isPwd) _pill(tr.pwd, const Color(0xFF0EA5E9)),
                      ],
                    ),
                  ],
                ),
              ),
              Icon(Icons.chevron_right, color: AppColors.slate400),
            ],
          ),
        ),
      ),
    );
  }

  Widget _avatar(HouseholdMember m, double size) => Stack(
        clipBehavior: Clip.none,
        children: [
          ProfileAvatar(initials: m.initials, photoUrl: m.photoUrl, size: size),
          if (m.isHead)
            Positioned(
              right: -3,
              bottom: -3,
              child: Container(
                padding: const EdgeInsets.all(2),
                decoration: BoxDecoration(
                  color: _headColor,
                  shape: BoxShape.circle,
                  border: Border.all(color: AppColors.surface, width: 2),
                ),
                child: Icon(Icons.star_rounded,
                    size: size * .28, color: Colors.white),
              ),
            ),
        ],
      );

  Widget _relationPill(HouseholdMember m) => m.isHead
      ? _pill(tr.householdHeadRole, _headColor, icon: Icons.star_rounded)
      : _pill(tr.relationLabel(m.relationship), const Color(0xFF6366F1));

  Widget _pill(String label, Color color, {IconData? icon}) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
        decoration: BoxDecoration(
          color: color.withValues(alpha: .13),
          borderRadius: BorderRadius.circular(50),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[
            Icon(icon, size: 12, color: color),
            const SizedBox(width: 3),
          ],
          Flexible(
            child: Text(label,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 11, fontWeight: FontWeight.w800, color: color)),
          ),
        ]),
      );

  void _showMember(HouseholdMember m) {
    const dash = '—';
    String v(String? s) => s == null ? dash : tr.valueLabel(s);
    final tags = <String>[
      if (m.isVoter) tr.registeredVoter,
      if (m.isSenior) tr.seniorCitizen,
      if (m.isPwd) tr.pwd,
      if (m.isSoloParent) tr.soloParent,
    ];
    showModalBottomSheet(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      backgroundColor: AppColors.surface,
      builder: (ctx) => SafeArea(
        child: ConstrainedBox(
          constraints:
              BoxConstraints(maxHeight: MediaQuery.of(ctx).size.height * .85),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
            child: Column(
              children: [
                _avatar(m, 80),
                const SizedBox(height: 12),
                Text(m.name,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w900,
                        color: AppColors.slate800)),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  alignment: WrapAlignment.center,
                  children: [
                    _relationPill(m),
                    if (m.isMe) _pill(tr.you, AppColors.primary),
                  ],
                ),
                const SizedBox(height: 16),
                for (final r in <(String, String)>[
                  (tr.sex, v(m.sex)),
                  (tr.age, m.age == null ? dash : tr.yearsOld(m.age!)),
                  (
                    tr.birthDate,
                    m.birthDate == null ? dash : _date(m.birthDate!)
                  ),
                  (tr.civilStatus, v(m.civilStatus)),
                  (tr.contactNumber, m.contactNumber ?? dash),
                  (tr.employment, v(m.employment)),
                  (tr.education, v(m.education)),
                ]) ...[
                  Divider(height: 1, color: AppColors.border),
                  _row(r.$1, r.$2),
                ],
                if (tags.isNotEmpty) ...[
                  Divider(height: 1, color: AppColors.border),
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 6,
                    runSpacing: 6,
                    alignment: WrapAlignment.center,
                    children: [
                      for (final t in tags)
                        _pill(t, AppColors.successText,
                            icon: Icons.check_circle),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  // ── Shared bits (same look as the Profile screen) ─────────────────────
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
