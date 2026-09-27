import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'complaint_api.dart';
import 'complaint_model.dart';
import 'complaint_widgets.dart';

/// One complaint: status tracker (Filed → Ongoing → Resolved), details,
/// the admin's reply and the attachment. Opening it marks the update as seen.
class ComplaintDetailScreen extends StatefulWidget {
  const ComplaintDetailScreen({
    super.key,
    required this.resident,
    required this.complaint,
  });

  final Resident resident;

  /// The row from the list; shown right away, then refreshed from the server.
  final Complaint complaint;

  @override
  State<ComplaintDetailScreen> createState() => _ComplaintDetailScreenState();
}

class _ComplaintDetailScreenState extends State<ComplaintDetailScreen> {
  final _api = ComplaintApi();
  late Complaint _c = widget.complaint;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  @override
  void dispose() {
    _api.dispose();
    super.dispose();
  }

  Future<void> _refresh() async {
    final res = await _api.detail(widget.resident.residentId, _c.id);
    if (!mounted) return;
    if (res.ok && res.data != null) {
      setState(() => _c = res.data!);
    } else if (res.message.isNotEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(res.message), backgroundColor: AppColors.danger));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: complaintAppBar(_c.complaintId, tr.complaintDetails),
      body: RefreshIndicator(
        color: AppColors.primary,
        onRefresh: _refresh,
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 560),
            child: ListView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
              children: [
                _header(),
                const SizedBox(height: 16),
                ComplaintSectionLabel(tr.status),
                ComplaintCard(child: _tracker()),
                const SizedBox(height: 16),
                ComplaintSectionLabel(tr.barangayReply),
                _replyCard(),
                const SizedBox(height: 16),
                ComplaintSectionLabel(tr.details),
                ComplaintCard(child: _details()),
                if (_c.hasAttachment) ...[
                  const SizedBox(height: 16),
                  ComplaintSectionLabel(tr.attachment),
                  ComplaintCard(child: _attachment()),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _header() {
    final color = ComplaintStyle.status(_c.status);
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
          Row(
            children: [
              Expanded(
                child: Text(_c.complaintId,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                        fontFamily: 'monospace',
                        color: Colors.white.withValues(alpha: .75),
                        fontSize: 12,
                        fontWeight: FontWeight.w800)),
              ),
              const SizedBox(width: 8),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(50),
                ),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(ComplaintStyle.statusIcon(_c.status),
                      size: 12, color: color),
                  const SizedBox(width: 4),
                  Text(tr.complaintStatusLabel(_c.status).toUpperCase(),
                      style: TextStyle(
                          fontSize: 10,
                          fontWeight: FontWeight.w900,
                          color: color)),
                ]),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Text(_c.title,
              style: const TextStyle(
                  color: Colors.white,
                  fontSize: 19,
                  height: 1.2,
                  fontWeight: FontWeight.w900)),
          const SizedBox(height: 6),
          Text(tr.submittedOn(ComplaintStyle.dateTime(_c.createdAt)),
              style: TextStyle(
                  color: Colors.white.withValues(alpha: .7),
                  fontSize: 12,
                  fontWeight: FontWeight.w500)),
        ],
      ),
    );
  }

  // Filed → Ongoing → Resolved
  Widget _tracker() {
    const steps = ['Pending', 'Ongoing', 'Resolved'];
    final labels = tr.complaintSteps;
    final current = steps.indexOf(_c.status).clamp(0, 2);
    return Row(
      children: [
        for (var i = 0; i < steps.length; i++) ...[
          Expanded(
            child: Column(
              children: [
                Container(
                  width: 34,
                  height: 34,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: i <= current
                        ? ComplaintStyle.status(steps[i])
                        : AppColors.bgBottom,
                  ),
                  child: Icon(
                    i < current
                        ? Icons.check
                        : ComplaintStyle.statusIcon(steps[i]),
                    size: 17,
                    color: i <= current ? Colors.white : AppColors.slate400,
                  ),
                ),
                const SizedBox(height: 6),
                Text(labels[i],
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        fontSize: 11,
                        fontWeight:
                            i == current ? FontWeight.w900 : FontWeight.w600,
                        color: i <= current
                            ? AppColors.slate800
                            : AppColors.slate400)),
              ],
            ),
          ),
          if (i < steps.length - 1)
            Expanded(
              child: Padding(
                padding: const EdgeInsets.only(bottom: 22),
                child: Container(
                  height: 3,
                  decoration: BoxDecoration(
                    color: i < current
                        ? ComplaintStyle.status(steps[i + 1])
                        : AppColors.bgBottom,
                    borderRadius: BorderRadius.circular(3),
                  ),
                ),
              ),
            ),
        ],
      ],
    );
  }

  Widget _replyCard() {
    if (!_c.hasReply) {
      return ComplaintCard(
        child: Row(
          children: [
            Icon(Icons.schedule, color: AppColors.slate400),
            const SizedBox(width: 12),
            Expanded(
              child: Text(tr.noReplyYet,
                  style: TextStyle(
                      fontSize: 12.5, height: 1.4, color: AppColors.slate500)),
            ),
          ],
        ),
      );
    }
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppColors.infoBg,
        borderRadius: BorderRadius.circular(20),
        border: Border(left: BorderSide(color: AppColors.primary, width: 4)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Icon(Icons.support_agent, size: 18, color: AppColors.primary),
            const SizedBox(width: 8),
            Text(tr.barangayAdmin,
                style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w900,
                    color: AppColors.primary)),
          ]),
          const SizedBox(height: 10),
          Text(_c.adminReply,
              style: TextStyle(
                  fontSize: 13.5, height: 1.5, color: AppColors.slate800)),
        ],
      ),
    );
  }

  Widget _details() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _row(Icons.label_outline, tr.category,
            tr.complaintCategoryLabel(_c.category)),
        _row(Icons.flag_outlined, tr.priority, tr.priorityLabel(_c.priority),
            color: ComplaintStyle.priority(_c.priority)),
        _row(Icons.location_on_outlined, tr.place, _c.location),
        _row(Icons.person_outline, tr.submittedBy,
            _c.isAnonymous ? tr.anonymous : widget.resident.fullName),
        const Divider(height: 24),
        Text(tr.descriptionLabel.toUpperCase(),
            style: TextStyle(
                fontSize: 10,
                fontWeight: FontWeight.w900,
                letterSpacing: .8,
                color: AppColors.slate400)),
        const SizedBox(height: 8),
        Text(_c.description,
            style: TextStyle(
                fontSize: 13.5, height: 1.55, color: AppColors.slate800)),
      ],
    );
  }

  Widget _row(IconData icon, String label, String value, {Color? color}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 18, color: AppColors.slate400),
            const SizedBox(width: 10),
            SizedBox(
              width: 86,
              child: Text(label,
                  style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AppColors.slate500)),
            ),
            Expanded(
              child: Text(value.isEmpty ? '—' : value,
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w800,
                      color: color ?? AppColors.slate800)),
            ),
          ],
        ),
      );

  Widget _attachment() {
    final url = _api.attachmentUrl(_c.attachmentUrl!);
    if (_c.attachmentIsPdf) {
      return Row(children: [
        const Icon(Icons.picture_as_pdf, color: AppColors.danger),
        const SizedBox(width: 10),
        Expanded(
          child: Text(tr.pdfAttached,
              style: TextStyle(fontSize: 12.5, color: AppColors.slate500)),
        ),
      ]);
    }
    return GestureDetector(
      onTap: () => showDialog(
        context: context,
        builder: (_) => Dialog(
          insetPadding: const EdgeInsets.all(12),
          child: InteractiveViewer(child: Image.network(url)),
        ),
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(14),
        child: Image.network(
          url,
          height: 200,
          width: double.infinity,
          fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => Container(
            height: 90,
            color: AppColors.surfaceAlt,
            alignment: Alignment.center,
            child: Text(tr.imageLoadFailed,
                style: TextStyle(fontSize: 12, color: AppColors.slate400)),
          ),
        ),
      ),
    );
  }
}
