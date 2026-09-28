import 'package:flutter/material.dart';

import '../complaint/complaint_widgets.dart';
import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'blotter_api.dart';
import 'blotter_detail_screen.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// BLOTTER / INCIDENTS — ported from the Blotter tab of SOE `user/incidents.php`.
///   • cases where the resident is a complainant or respondent (read-only)
///   • status, their role, next hearing
///   • blotter reports are filed at the Barangay Hall, not online
///
/// Talks to `user/backend/blotter.php`. Kept in its own `blotter/` folder.
/// ─────────────────────────────────────────────────────────────────────────
class BlotterScreen extends StatefulWidget {
  const BlotterScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<BlotterScreen> createState() => _BlotterScreenState();
}

class _BlotterScreenState extends State<BlotterScreen> {
  final _api = BlotterApi();
  BlotterList? _data;
  String? _error;
  bool _loading = true;
  bool _activeOnly = false;

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
    final res = await _api.list(widget.resident.residentId);
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
      appBar: complaintAppBar(tr.blotterTitle, tr.blotterSubtitle,
          icon: Icons.gavel_outlined,
          actions: [
            IconButton(
              tooltip: tr.refresh,
              onPressed: () {
                setState(() => _loading = true);
                _load();
              },
              icon: const Icon(Icons.refresh),
            ),
          ]),
      body: _loading
          ? Center(child: CircularProgressIndicator(color: AppColors.primary))
          : RefreshIndicator(
              color: AppColors.primary,
              onRefresh: _load,
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 620),
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
                    children: _content(),
                  ),
                ),
              ),
            ),
    );
  }

  List<Widget> _content() {
    final d = _data;
    final cases = d == null
        ? const <BlotterCase>[]
        : (_activeOnly ? d.cases.where((c) => c.isActive).toList() : d.cases);
    return [
      _filingNotice(),
      const SizedBox(height: 16),
      if (d == null)
        _errorBox(_error ?? tr.blotterLoadFailed)
      else ...[
        Row(children: [
          Expanded(
              child: _stat(tr.blotterTotal, d.total, Icons.folder_outlined,
                  AppColors.primary,
                  selected: !_activeOnly, onTap: () {
            setState(() => _activeOnly = false);
          })),
          const SizedBox(width: 12),
          Expanded(
              child: _stat(tr.blotterActive, d.active, Icons.pending_actions,
                  const Color(0xFFE11D48),
                  selected: _activeOnly, onTap: () {
            setState(() => _activeOnly = true);
          })),
        ]),
        const SizedBox(height: 18),
        if (cases.isEmpty)
          _empty()
        else
          for (final c in cases) ...[
            _caseCard(c),
            const SizedBox(height: 12),
          ],
      ],
    ];
  }

  Widget _filingNotice() => Container(
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
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(tr.blotterFilingTitle,
                      style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w900,
                          color: AppColors.infoText)),
                  const SizedBox(height: 4),
                  Text(tr.blotterFilingBody,
                      style: TextStyle(
                          fontSize: 12,
                          height: 1.4,
                          color: AppColors.infoText)),
                ],
              ),
            ),
          ],
        ),
      );

  Widget _stat(String label, int value, IconData icon, Color color,
      {required bool selected, required VoidCallback onTap}) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: AppColors.surface,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(
                color: selected ? color : AppColors.border,
                width: selected ? 1.6 : 1),
          ),
          child: Row(children: [
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                  color: color.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(12)),
              child: Icon(icon, color: color, size: 20),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('$value',
                      style: TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w900,
                          color: color)),
                  Text(label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w700,
                          color: AppColors.slate500)),
                ],
              ),
            ),
          ]),
        ),
      ),
    );
  }

  Widget _caseCard(BlotterCase c) {
    final color = BlotterStyle.status(c.status);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: () => Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => BlotterDetailScreen(
                resident: widget.resident, caseId: c.id, summary: c))),
        child: ComplaintCard(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Expanded(
                  child: Text(c.caseNumber,
                      style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w800,
                          letterSpacing: .4,
                          color: AppColors.slate500)),
                ),
                Flexible(
                  child: ComplaintPill(tr.blotterStatus(c.status), color,
                      icon: BlotterStyle.icon(c.status)),
                ),
              ]),
              const SizedBox(height: 8),
              Text(c.incidentType,
                  style: TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w900,
                      color: AppColors.slate800)),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 6,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  ComplaintPill(tr.youAreRole(tr.blotterRole(c.myRole)),
                      BlotterStyle.role(c.myRole),
                      icon: Icons.person_outline),
                  _meta(Icons.event_outlined,
                      ComplaintStyle.date(c.incidentDate)),
                  if (c.location != null)
                    _meta(Icons.place_outlined, c.location!),
                ],
              ),
              if (c.nextHearing != null) ...[
                const SizedBox(height: 12),
                _nextHearing(c.nextHearing!),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _meta(IconData icon, String text) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: AppColors.slate400),
          const SizedBox(width: 4),
          Flexible(
            child: Text(text,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(fontSize: 12, color: AppColors.slate500)),
          ),
        ],
      );

  Widget _nextHearing(BlotterHearing h) => Container(
        width: double.infinity,
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: const Color(0xFF6366F1).withValues(alpha: .10),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(children: [
          const Icon(Icons.event_available, size: 20, color: Color(0xFF6366F1)),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              '${tr.nextHearing}: ${ComplaintStyle.date(h.date)}'
              '${h.time == null ? '' : ' · ${BlotterStyle.time(h.time)}'}'
              '${h.location == null ? '' : ' · ${h.location}'}',
              style: const TextStyle(
                  fontSize: 12.5,
                  fontWeight: FontWeight.w800,
                  color: Color(0xFF6366F1)),
            ),
          ),
        ]),
      );

  Widget _empty() => ComplaintCard(
        child: Column(children: [
          const SizedBox(height: 8),
          Icon(Icons.gavel_outlined, size: 48, color: AppColors.slate400),
          const SizedBox(height: 10),
          Text(tr.blotterNone,
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w900,
                  color: AppColors.slate800)),
          const SizedBox(height: 6),
          Text(tr.blotterNoneBody,
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 12.5, height: 1.4, color: AppColors.slate500)),
          const SizedBox(height: 8),
        ]),
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
}
