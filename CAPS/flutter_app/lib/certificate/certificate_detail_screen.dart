import 'package:flutter/material.dart';

import '../complaint/complaint_widgets.dart';
import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'certificate_api.dart';

/// One document request: status (with what to do next), details, the
/// requirements and files sent, and the status history. A Pending request
/// can be cancelled.
class CertificateDetailScreen extends StatefulWidget {
  const CertificateDetailScreen({
    super.key,
    required this.resident,
    required this.requestId,
    this.summary,
  });

  final Resident resident;
  final int requestId;
  final CertRequest? summary;

  @override
  State<CertificateDetailScreen> createState() =>
      _CertificateDetailScreenState();
}

class _CertificateDetailScreenState extends State<CertificateDetailScreen> {
  final _api = CertificateApi();
  CertRequest? _r;
  String? _error;
  bool _loading = true;
  bool _cancelling = false;

  @override
  void initState() {
    super.initState();
    _r = widget.summary;
    _load();
  }

  @override
  void dispose() {
    _api.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final res = await _api.detail(widget.resident.residentId, widget.requestId);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res.ok) {
        _r = res.data;
        _error = null;
      } else {
        _error = res.message;
      }
    });
  }

  Future<void> _cancel() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr.cancelRequestTitle),
        content: Text(tr.cancelRequestBody),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(tr.keepRequest)),
          FilledButton(
              style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(tr.cancelRequest)),
        ],
      ),
    );
    if (ok != true) return;
    setState(() => _cancelling = true);
    final res = await _api.cancel(widget.resident.residentId, widget.requestId);
    if (!mounted) return;
    setState(() => _cancelling = false);
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(res.message),
      backgroundColor: res.ok ? null : AppColors.danger,
    ));
    if (res.ok) _load();
  }

  @override
  Widget build(BuildContext context) {
    final r = _r;
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: complaintAppBar(r?.referenceNo ?? tr.certTitle, tr.requestDetails,
          icon: Icons.description_outlined),
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
                if (r != null) ..._content(r),
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

  List<Widget> _content(CertRequest r) {
    final color = CertStyle.status(r.status);
    const dash = '—';
    return [
      ComplaintCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ComplaintPill(tr.certStatus(r.status), color,
                icon: CertStyle.statusIcon(r.status)),
            const SizedBox(height: 12),
            Text(r.docType,
                style: TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.w900,
                    color: AppColors.slate800)),
            const SizedBox(height: 4),
            Text(tr.requestedOn(ComplaintStyle.dateTime(r.requestedAt)),
                style: TextStyle(fontSize: 12, color: AppColors.slate500)),
          ],
        ),
      ),
      const SizedBox(height: 14),
      _statusBox(r),
      const SizedBox(height: 18),
      ComplaintSectionLabel(tr.requestDetails),
      ComplaintCard(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        child: Column(children: [
          _row(tr.referenceNo, r.referenceNo ?? dash),
          if (r.docNumber != null) ...[
            Divider(height: 1, color: AppColors.border),
            _row(tr.documentNo, r.docNumber!),
          ],
          Divider(height: 1, color: AppColors.border),
          _row(tr.purposeLabel, r.purpose ?? dash),
          for (final e in r.extra) ...[
            Divider(height: 1, color: AppColors.border),
            _row(e.key, e.value),
          ],
        ]),
      ),
      if (r.requirementsRequired.isNotEmpty) ...[
        const SizedBox(height: 18),
        ComplaintSectionLabel(tr.requirementsLabel),
        ComplaintCard(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Column(children: [
            for (final q in r.requirementsRequired)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(children: [
                  Icon(
                      r.requirementsChecked.contains(q)
                          ? Icons.check_circle
                          : Icons.radio_button_unchecked,
                      size: 18,
                      color: r.requirementsChecked.contains(q)
                          ? AppColors.successText
                          : AppColors.slate400),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(q,
                        style:
                            TextStyle(fontSize: 13, color: AppColors.slate800)),
                  ),
                  if (r.files.contains(q))
                    Icon(Icons.image_outlined,
                        size: 18, color: AppColors.primary),
                ]),
              ),
          ]),
        ),
      ],
      if (r.history.isNotEmpty) ...[
        const SizedBox(height: 18),
        ComplaintSectionLabel(tr.statusHistory),
        ComplaintCard(
          child: Column(children: [
            for (var i = 0; i < r.history.length; i++)
              _event(r.history[i], last: i == r.history.length - 1),
          ]),
        ),
      ],
      if (r.canCancel) ...[
        const SizedBox(height: 22),
        SizedBox(
          height: 48,
          child: OutlinedButton.icon(
            onPressed: _cancelling ? null : _cancel,
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.danger,
              side: const BorderSide(color: AppColors.danger),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(14)),
            ),
            icon: const Icon(Icons.close),
            label: Text(tr.cancelRequest,
                style: const TextStyle(fontWeight: FontWeight.w800)),
          ),
        ),
      ],
    ];
  }

  Widget _statusBox(CertRequest r) {
    switch (r.status) {
      case 'Ready to Pick Up':
        return _box(
            tr.readyInfo(ComplaintStyle.date(r.pickupUntil)),
            AppColors.infoBg,
            AppColors.infoBorder,
            AppColors.infoText,
            Icons.inventory_2_outlined);
      case 'Released':
        return _box(tr.statusInfo(r.status), AppColors.successBg,
            AppColors.successBorder, AppColors.successText, Icons.task_alt);
      case 'Rejected':
        return _box(
            '${tr.statusInfo(r.status)}'
            '${r.rejectionReason == null ? '' : '\n${tr.reasonLabel}: ${r.rejectionReason}'}',
            AppColors.dangerBg,
            AppColors.dangerBorder,
            AppColors.dangerText,
            Icons.block);
      case 'Expired':
        return _box(tr.statusInfo(r.status), AppColors.warnBg,
            AppColors.warnBorder, AppColors.warnText, Icons.hourglass_disabled);
      default:
        return _box(tr.statusInfo(r.status), AppColors.warnBg,
            AppColors.warnBorder, AppColors.warnText, Icons.schedule);
    }
  }

  Widget _event(CertLog e, {required bool last}) {
    final color = CertStyle.status(e.status);
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
                  Text(tr.certStatus(e.status),
                      style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w800,
                          color: color)),
                  if (e.note != null)
                    Text(e.note!,
                        style: TextStyle(
                            fontSize: 12.5,
                            height: 1.35,
                            color: AppColors.slate800)),
                  Text(ComplaintStyle.dateTime(e.at),
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
