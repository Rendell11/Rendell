import 'package:flutter/material.dart';

import '../complaint/complaint_widgets.dart';
import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'blotter_api.dart';

/// One blotter case: incident, parties, hearings, notices to the resident,
/// resolution / transfer and the case history. Read-only.
class BlotterDetailScreen extends StatefulWidget {
  const BlotterDetailScreen({
    super.key,
    required this.resident,
    required this.caseId,
    this.summary,
  });

  final Resident resident;
  final int caseId;

  /// Row from the list, shown while the full case loads.
  final BlotterCase? summary;

  @override
  State<BlotterDetailScreen> createState() => _BlotterDetailScreenState();
}

class _BlotterDetailScreenState extends State<BlotterDetailScreen> {
  final _api = BlotterApi();
  BlotterCase? _case;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _case = widget.summary;
    _load();
  }

  @override
  void dispose() {
    _api.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final res = await _api.detail(widget.resident.residentId, widget.caseId);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res.ok) {
        _case = res.data;
        _error = null;
      } else {
        _error = res.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final c = _case;
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: complaintAppBar(c?.caseNumber ?? tr.blotterTitle, tr.details,
          icon: Icons.gavel_outlined),
      body: RefreshIndicator(
        color: AppColors.primary,
        onRefresh: _load,
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 620),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
              children: [
                if (_error != null) ...[
                  _box(_error!, AppColors.dangerBg, AppColors.dangerBorder,
                      AppColors.dangerText, Icons.error_outline),
                  const SizedBox(height: 14),
                ],
                if (c != null) ..._content(c),
                if (_loading)
                  Padding(
                    padding: const EdgeInsets.all(24),
                    child: Center(
                        child: CircularProgressIndicator(
                            color: AppColors.primary)),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  List<Widget> _content(BlotterCase c) {
    final color = BlotterStyle.status(c.status);
    return [
      // Header
      ComplaintCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Wrap(spacing: 8, runSpacing: 6, children: [
              ComplaintPill(tr.blotterStatus(c.status), color,
                  icon: BlotterStyle.icon(c.status)),
              ComplaintPill(tr.youAreRole(tr.blotterRole(c.myRole)),
                  BlotterStyle.role(c.myRole),
                  icon: Icons.person_outline),
            ]),
            const SizedBox(height: 12),
            Text(c.incidentType,
                style: TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.w900,
                    color: AppColors.slate800)),
            const SizedBox(height: 4),
            Text(tr.filedOn(ComplaintStyle.date(c.filedAt)),
                style: TextStyle(fontSize: 12, color: AppColors.slate500)),
          ],
        ),
      ),
      if (c.nextHearing != null) ...[
        const SizedBox(height: 14),
        _nextHearing(c.nextHearing!),
      ],
      if (c.resolution != null) ...[
        const SizedBox(height: 14),
        _box(
            '${tr.resolutionLabel}: ${c.resolution!}'
            '${c.resolvedAt == null ? '' : '\n${ComplaintStyle.date(c.resolvedAt)}'}',
            AppColors.successBg,
            AppColors.successBorder,
            AppColors.successText,
            Icons.handshake_outlined),
      ],
      if (c.transferDestination != null) ...[
        const SizedBox(height: 14),
        _box(
            '${tr.transferredTo}: ${c.transferDestination!}'
            '${c.transferDate == null ? '' : '\n${ComplaintStyle.date(c.transferDate)}'}',
            AppColors.warnBg,
            AppColors.warnBorder,
            AppColors.warnText,
            Icons.local_police_outlined),
      ],
      const SizedBox(height: 18),
      ComplaintSectionLabel(tr.incidentDetails),
      ComplaintCard(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        child: Column(children: [
          _row(
              tr.incidentDate,
              '${ComplaintStyle.date(c.incidentDate)}'
              '${c.incidentTime == null ? '' : ' · ${BlotterStyle.time(c.incidentTime)}'}'),
          Divider(height: 1, color: AppColors.border),
          _row(tr.incidentLocation, c.location ?? '—'),
          if (c.narrative != null) ...[
            Divider(height: 1, color: AppColors.border),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(tr.narrativeLabel,
                      style: TextStyle(
                          fontSize: 12.5,
                          fontWeight: FontWeight.w600,
                          color: AppColors.slate500)),
                  const SizedBox(height: 6),
                  SizedBox(
                    width: double.infinity,
                    child: SelectableText(c.narrative!,
                        style: TextStyle(
                            fontSize: 13.5,
                            height: 1.5,
                            color: AppColors.slate800)),
                  ),
                ],
              ),
            ),
          ],
        ]),
      ),
      if (c.parties.isNotEmpty) ...[
        const SizedBox(height: 18),
        ComplaintSectionLabel(tr.partiesLabel),
        ComplaintCard(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
          child: Column(children: [
            for (var i = 0; i < c.parties.length; i++) ...[
              if (i > 0) Divider(height: 1, color: AppColors.border),
              _party(c.parties[i]),
            ],
          ]),
        ),
      ],
      const SizedBox(height: 18),
      ComplaintSectionLabel(tr.hearingsLabel),
      if (c.hearings.isEmpty)
        ComplaintCard(
            child: Text(tr.noHearings,
                style: TextStyle(fontSize: 13, color: AppColors.slate500)))
      else
        for (final h in c.hearings) ...[
          _hearing(h),
          const SizedBox(height: 10),
        ],
      if (c.notices.isNotEmpty) ...[
        const SizedBox(height: 8),
        ComplaintSectionLabel(tr.noticesToYou),
        ComplaintCard(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
          child: Column(children: [
            for (var i = 0; i < c.notices.length; i++) ...[
              if (i > 0) Divider(height: 1, color: AppColors.border),
              _notice(c.notices[i]),
            ],
          ]),
        ),
      ],
      if (c.timeline.isNotEmpty) ...[
        const SizedBox(height: 18),
        ComplaintSectionLabel(tr.caseHistory),
        ComplaintCard(
          child: Column(children: [
            for (var i = 0; i < c.timeline.length; i++)
              _event(c.timeline[i], last: i == c.timeline.length - 1),
          ]),
        ),
      ],
    ];
  }

  Widget _nextHearing(BlotterHearing h) {
    const c = Color(0xFF6366F1);
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [
          c.withValues(alpha: .16),
          c.withValues(alpha: .06),
        ]),
        border: Border.all(color: c.withValues(alpha: .35)),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            const Icon(Icons.event_available, color: c),
            const SizedBox(width: 8),
            Expanded(
              child: Text('${tr.nextHearing} · ${tr.hearingNo(h.no)}',
                  style: const TextStyle(
                      fontSize: 13, fontWeight: FontWeight.w900, color: c)),
            ),
          ]),
          const SizedBox(height: 8),
          Text(
              '${ComplaintStyle.date(h.date)}'
              '${h.time == null ? '' : ' · ${BlotterStyle.time(h.time)}'}',
              style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                  color: AppColors.slate800)),
          if (h.location != null)
            Text(h.location!,
                style: TextStyle(fontSize: 13, color: AppColors.slate500)),
          const SizedBox(height: 8),
          Text(tr.hearingReminder,
              style: TextStyle(
                  fontSize: 12, height: 1.4, color: AppColors.slate500)),
        ],
      ),
    );
  }

  Widget _party(BlotterParty p) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Row(children: [
          Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
                color: BlotterStyle.role(p.role).withValues(alpha: .12),
                shape: BoxShape.circle),
            child: Icon(
                p.role == 'Respondent'
                    ? Icons.person_off_outlined
                    : Icons.record_voice_over_outlined,
                size: 18,
                color: BlotterStyle.role(p.role)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr.partyName(p.name),
                    style: TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                        color: AppColors.slate800)),
                Text(tr.blotterRole(p.role),
                    style: TextStyle(
                        fontSize: 12, color: BlotterStyle.role(p.role))),
              ],
            ),
          ),
          if (p.isMe) ComplaintPill(tr.youLabel, AppColors.primary),
        ]),
      );

  Widget _hearing(BlotterHearing h) {
    final color = h.status == 'Completed'
        ? const Color(0xFF16A34A)
        : h.status == 'Cancelled'
            ? const Color(0xFF64748B)
            : const Color(0xFF6366F1);
    return ComplaintCard(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Expanded(
              child: Text(tr.hearingNo(h.no),
                  style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w900,
                      color: AppColors.slate800)),
            ),
            ComplaintPill(tr.hearingStatus(h.status), color),
          ]),
          const SizedBox(height: 6),
          Text(
              '${ComplaintStyle.date(h.date)}'
              '${h.time == null ? '' : ' · ${BlotterStyle.time(h.time)}'}'
              '${h.location == null ? '' : ' · ${h.location}'}',
              style: TextStyle(fontSize: 12.5, color: AppColors.slate500)),
          if (h.outcome != null) ...[
            const SizedBox(height: 6),
            Text(h.outcome!,
                style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w800,
                    color: AppColors.slate800)),
          ],
          if ((h.remarks ?? h.cancelReason) != null) ...[
            const SizedBox(height: 4),
            Text((h.remarks ?? h.cancelReason)!,
                style: TextStyle(
                    fontSize: 12.5, height: 1.4, color: AppColors.slate500)),
          ],
        ],
      ),
    );
  }

  Widget _notice(BlotterNotice n) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Row(children: [
          const Icon(Icons.mark_email_read_outlined,
              size: 20, color: Color(0xFF0EA5E9)),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(n.type,
                    style: TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w800,
                        color: AppColors.slate800)),
                Text(
                    [
                      if (n.noticeNo != null) n.noticeNo!,
                      ComplaintStyle.date(n.issuedAt),
                    ].join(' · '),
                    style: TextStyle(fontSize: 12, color: AppColors.slate500)),
                if (n.hearingDate != null)
                  Text(
                      '${tr.hearingsLabel}: ${ComplaintStyle.date(n.hearingDate)}'
                      '${n.hearingTime == null ? '' : ' · ${BlotterStyle.time(n.hearingTime)}'}',
                      style:
                          TextStyle(fontSize: 12, color: AppColors.slate500)),
              ],
            ),
          ),
        ]),
      );

  Widget _event(BlotterEvent e, {required bool last}) {
    final color =
        e.status == null ? AppColors.slate400 : BlotterStyle.status(e.status!);
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 20,
            child: Column(children: [
              Container(
                width: 12,
                height: 12,
                margin: const EdgeInsets.only(top: 3),
                decoration: BoxDecoration(color: color, shape: BoxShape.circle),
              ),
              if (!last)
                Expanded(child: Container(width: 2, color: AppColors.border)),
            ]),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: last ? 0 : 14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(e.action,
                      style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w800,
                          color: AppColors.slate800)),
                  Text(
                      [
                        if (e.status != null) tr.blotterStatus(e.status!),
                        ComplaintStyle.dateTime(e.at),
                      ].join(' · '),
                      style:
                          TextStyle(fontSize: 11.5, color: AppColors.slate500)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

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

  Widget _box(String msg, Color bg, Color border, Color fg, IconData icon) =>
      Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: bg,
          border: Border.all(color: border),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 18, color: fg),
            const SizedBox(width: 10),
            Expanded(
              child: Text(msg,
                  style: TextStyle(
                      fontSize: 13,
                      height: 1.4,
                      fontWeight: FontWeight.w700,
                      color: fg)),
            ),
          ],
        ),
      );
}
