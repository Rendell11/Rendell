import 'package:flutter/material.dart';

import '../complaint/complaint_widgets.dart';
import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'certificate_api.dart';
import 'certificate_detail_screen.dart';
import 'certificate_form_screen.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// DOCUMENTS & CERTIFICATES — ported from SOE `user/legal_docu.php`.
///   • my requests with status (Pending → Under review → Ready to pick up →
///     Released; or Rejected / Expired), reference no. and pick-up deadline
///   • "Request a document" opens the form (types set up by the admin)
///
/// Talks to `user/backend/certificate.php`. Kept in its own `certificate/` folder.
/// ─────────────────────────────────────────────────────────────────────────
class CertificateScreen extends StatefulWidget {
  const CertificateScreen(
      {super.key, required this.resident, this.openForm = false});

  final Resident resident;

  /// Open the request form right away (dashboard "Make your first request").
  final bool openForm;

  @override
  State<CertificateScreen> createState() => _CertificateScreenState();
}

enum _Filter { all, pending, ready, released, rejected }

class _CertificateScreenState extends State<CertificateScreen> {
  final _api = CertificateApi();
  CertList? _data;
  String? _error;
  bool _loading = true;
  _Filter _filter = _Filter.all;

  @override
  void initState() {
    super.initState();
    _load();
    if (widget.openForm) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _newRequest());
    }
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

  Future<void> _newRequest() async {
    final sent = await Navigator.of(context).push<CertRequest>(
        MaterialPageRoute(
            builder: (_) => CertificateFormScreen(resident: widget.resident)));
    if (sent != null && mounted) {
      await showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: const Icon(Icons.check_circle,
              color: Color(0xFF16A34A), size: 44),
          title: Text(tr.requestSentTitle),
          content: Text(tr.requestSentBody(sent.referenceNo ?? '—'),
              textAlign: TextAlign.center),
          actions: [
            FilledButton(
                onPressed: () => Navigator.pop(ctx), child: Text(tr.close)),
          ],
        ),
      );
      _load();
    }
  }

  Future<void> _open(CertRequest r) async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => CertificateDetailScreen(
            resident: widget.resident, requestId: r.id, summary: r)));
    _load();
  }

  bool _match(CertRequest r) {
    switch (_filter) {
      case _Filter.pending:
        return r.isPending;
      case _Filter.ready:
        return r.status == 'Ready to Pick Up';
      case _Filter.released:
        return r.status == 'Released';
      case _Filter.rejected:
        return r.isRejected;
      case _Filter.all:
        return true;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: complaintAppBar(tr.certTitle, tr.certSubtitle,
          icon: Icons.description_outlined,
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
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _newRequest,
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: Text(tr.requestDocument,
            style: const TextStyle(fontWeight: FontWeight.w800)),
      ),
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
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 100),
                    children: _content(),
                  ),
                ),
              ),
            ),
    );
  }

  List<Widget> _content() {
    final d = _data;
    if (d == null) return [_errorBox(_error ?? tr.certLoadFailed)];
    final items = d.requests.where(_match).toList();
    final chips = <(_Filter, String, int)>[
      (_Filter.all, tr.all, d.total),
      (_Filter.pending, tr.pending, d.pending),
      (_Filter.ready, tr.certReady, d.ready),
      (_Filter.released, tr.certReleased, d.released),
      (_Filter.rejected, tr.rejected, d.rejected),
    ];
    return [
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final c in chips)
            ChoiceChip(
              label: Text('${c.$2} (${c.$3})'),
              selected: _filter == c.$1,
              onSelected: (_) => setState(() => _filter = c.$1),
              selectedColor: AppColors.primary.withValues(alpha: .16),
              labelStyle: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w800,
                  color:
                      _filter == c.$1 ? AppColors.primary : AppColors.slate500),
              side: BorderSide(
                  color:
                      _filter == c.$1 ? AppColors.primary : AppColors.border),
              showCheckmark: false,
            ),
        ],
      ),
      const SizedBox(height: 16),
      if (d.requests.isEmpty)
        _empty()
      else if (items.isEmpty)
        Padding(
          padding: const EdgeInsets.all(24),
          child: Text(tr.noRequests,
              textAlign: TextAlign.center,
              style: TextStyle(color: AppColors.slate500)),
        )
      else
        for (final r in items) ...[
          CertRequestTile(request: r, onTap: () => _open(r)),
          const SizedBox(height: 12),
        ],
    ];
  }

  Widget _empty() => ComplaintCard(
        child: Column(children: [
          const SizedBox(height: 8),
          Icon(Icons.description_outlined, size: 48, color: AppColors.slate400),
          const SizedBox(height: 10),
          Text(tr.noCertRequests,
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w900,
                  color: AppColors.slate800)),
          const SizedBox(height: 6),
          Text(tr.noCertRequestsBody,
              textAlign: TextAlign.center,
              style: TextStyle(
                  fontSize: 12.5, height: 1.4, color: AppColors.slate500)),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: _newRequest,
            icon: const Icon(Icons.add),
            label: Text(tr.requestDocument),
          ),
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

/// One request row (also used on the dashboard "Recent Requests" card).
class CertRequestTile extends StatelessWidget {
  const CertRequestTile({super.key, required this.request, this.onTap});

  final CertRequest request;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final r = request;
    final color = CertStyle.status(r.status);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: onTap,
        child: ComplaintCard(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                  color: color.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(14)),
              child: Icon(CertStyle.statusIcon(r.status), color: color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(children: [
                    Expanded(
                      child: Text(r.docType,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                              fontSize: 14.5,
                              fontWeight: FontWeight.w900,
                              color: AppColors.slate800)),
                    ),
                    if (r.isUnread)
                      Container(
                        width: 9,
                        height: 9,
                        margin: const EdgeInsets.only(left: 6),
                        decoration: const BoxDecoration(
                            color: Color(0xFFEF4444), shape: BoxShape.circle),
                      ),
                  ]),
                  const SizedBox(height: 2),
                  Text(
                      '${r.referenceNo ?? '—'} · ${tr.requestedOn(ComplaintStyle.date(r.requestedAt))}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style:
                          TextStyle(fontSize: 11.5, color: AppColors.slate500)),
                  const SizedBox(height: 6),
                  Wrap(
                    spacing: 8,
                    runSpacing: 4,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    children: [
                      ComplaintPill(tr.certStatus(r.status), color),
                      if (r.pickupUntil != null)
                        Text(tr.pickUpUntil(ComplaintStyle.date(r.pickupUntil)),
                            style: TextStyle(
                                fontSize: 11.5,
                                fontWeight: FontWeight.w700,
                                color: color)),
                    ],
                  ),
                ],
              ),
            ),
            Icon(Icons.chevron_right, color: AppColors.slate400),
          ]),
        ),
      ),
    );
  }
}
