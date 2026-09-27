import 'package:flutter/material.dart';

import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'complaint_api.dart';
import 'complaint_detail_screen.dart';
import 'complaint_form_screen.dart';
import 'complaint_model.dart';
import 'complaint_widgets.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// COMPLAINTS — resident side (ported from SOE `user/incidents.php`,
/// Complaints tab). Talks to `user/backend/complaint.php`; the admin handles
/// the same rows in `admin/complaint/`.
///   • Summary cards (Total / Pending / Ongoing / Resolved)
///   • Search + status filter chips
///   • List of the resident's complaints, "New reply" badge on unseen updates
///   • "File a Complaint" → ComplaintFormScreen, tap a row → detail
///
/// Kept in its own `complaint/` folder for isolated debugging.
/// ─────────────────────────────────────────────────────────────────────────
class ComplaintScreen extends StatefulWidget {
  const ComplaintScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<ComplaintScreen> createState() => _ComplaintScreenState();
}

class _ComplaintScreenState extends State<ComplaintScreen> {
  final _api = ComplaintApi();
  final _search = TextEditingController();

  ComplaintList _data = const ComplaintList();
  bool _loading = true;
  String? _error;
  String _filter = 'All'; // All | Pending | Ongoing | Resolved

  int get _rid => widget.resident.residentId;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _api.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final res = await _api.list(_rid);
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res.ok && res.data != null) {
        _data = res.data!;
        _error = null;
      } else {
        _error = res.message;
      }
    });
  }

  List<Complaint> get _visible {
    final q = _search.text.trim().toLowerCase();
    return _data.complaints.where((c) {
      final matchStatus = _filter == 'All' || c.status == _filter;
      final matchQ = q.isEmpty ||
          '${c.complaintId} ${c.title} ${c.category} ${c.location}'
              .toLowerCase()
              .contains(q);
      return matchStatus && matchQ;
    }).toList();
  }

  Future<void> _openForm() async {
    final code = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => ComplaintFormScreen(
          resident: widget.resident,
          defaultLocation: _data.defaultLocation,
        ),
      ),
    );
    if (code == null || !mounted) return;
    setState(() => _filter = 'All');
    await _load();
  }

  Future<void> _openDetail(Complaint c) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) =>
            ComplaintDetailScreen(resident: widget.resident, complaint: c),
      ),
    );
    if (mounted) _load(); // refresh the "New reply" badges
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFEEF2FB),
      appBar: complaintAppBar(
        'Complaints',
        'Magsumite at subaybayan ang iyong reklamo',
        actions: [
          IconButton(
            tooltip: 'I-refresh',
            onPressed: () {
              setState(() => _loading = true);
              _load();
            },
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openForm,
        backgroundColor: AppColors.primary,
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: const Text('File a Complaint',
            style: TextStyle(fontWeight: FontWeight.w800)),
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
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                    children: [
                      if (_error != null) _errorBanner(),
                      _statCards(),
                      const SizedBox(height: 16),
                      _searchBar(),
                      const SizedBox(height: 10),
                      _filterChips(),
                      const SizedBox(height: 16),
                      const ComplaintSectionLabel('My Complaints'),
                      ..._listBody(),
                    ],
                  ),
                ),
              ),
            ),
    );
  }

  Widget _errorBanner() => Container(
        margin: const EdgeInsets.only(bottom: 14),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.danger.withOpacity(.08),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: AppColors.danger.withOpacity(.25)),
        ),
        child: Row(children: [
          Icon(Icons.error_outline, color: AppColors.danger, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(_error!,
                style: TextStyle(
                    color: AppColors.danger,
                    fontSize: 12,
                    fontWeight: FontWeight.w700)),
          ),
        ]),
      );

  // ── Summary cards ─────────────────────────────────────────────────────
  Widget _statCards() {
    final s = _data.stats;
    final cards = [
      ('Total', s.total, Icons.folder_open, const Color(0xFF6366F1)),
      ('Pending', s.pending, Icons.hourglass_top, ComplaintStyle.pending),
      ('Ongoing', s.ongoing, Icons.autorenew, ComplaintStyle.ongoing),
      ('Resolved', s.resolved, Icons.check_circle, ComplaintStyle.resolved),
    ];
    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 12,
      crossAxisSpacing: 12,
      childAspectRatio: 1.9,
      children: [
        for (final c in cards)
          InkWell(
            borderRadius: BorderRadius.circular(20),
            onTap: () =>
                setState(() => _filter = c.$1 == 'Total' ? 'All' : c.$1),
            child: ComplaintCard(
              padding: const EdgeInsets.all(14),
              child: Row(
                children: [
                  Container(
                    width: 38,
                    height: 38,
                    decoration: BoxDecoration(
                        color: c.$4.withOpacity(.12),
                        borderRadius: BorderRadius.circular(12)),
                    child: Icon(c.$3, size: 20, color: c.$4),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text('${c.$2}',
                            style: TextStyle(
                                fontSize: 22,
                                fontWeight: FontWeight.w900,
                                color: c.$4)),
                        Text(c.$1.toUpperCase(),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                                fontSize: 9.5,
                                fontWeight: FontWeight.w900,
                                letterSpacing: 1,
                                color: AppColors.slate400)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }

  // ── Search + filters ──────────────────────────────────────────────────
  Widget _searchBar() => TextField(
        controller: _search,
        onChanged: (_) => setState(() {}),
        decoration: AppTheme.field(
          'Hanapin (ID, pamagat, kategorya)…',
          icon: Icons.search,
          suffix: _search.text.isEmpty
              ? null
              : IconButton(
                  icon: const Icon(Icons.close, size: 18),
                  onPressed: () => setState(_search.clear),
                ),
        ),
      );

  Widget _filterChips() {
    const options = ['All', 'Pending', 'Ongoing', 'Resolved'];
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          for (final o in options)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                label: Text(o),
                selected: _filter == o,
                onSelected: (_) => setState(() => _filter = o),
                selectedColor:
                    (o == 'All' ? AppColors.primary : ComplaintStyle.status(o))
                        .withOpacity(.15),
                labelStyle: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.w800,
                  color: _filter == o
                      ? (o == 'All'
                          ? AppColors.primary
                          : ComplaintStyle.status(o))
                      : AppColors.slate500,
                ),
                side: BorderSide(color: AppColors.bgBottom),
                backgroundColor: Colors.white,
                showCheckmark: false,
              ),
            ),
        ],
      ),
    );
  }

  // ── List ──────────────────────────────────────────────────────────────
  List<Widget> _listBody() {
    if (_data.complaints.isEmpty) {
      return [
        _emptyState(Icons.inbox_outlined, 'Wala ka pang reklamo',
            'Pindutin ang "File a Complaint" para magsumite ng reklamo sa barangay.'),
      ];
    }
    final items = _visible;
    if (items.isEmpty) {
      return [
        _emptyState(Icons.search_off, 'Walang tugma',
            'Walang reklamong tugma sa iyong hinahanap o filter.'),
      ];
    }
    return [
      for (final c in items)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: _complaintTile(c),
        ),
    ];
  }

  Widget _complaintTile(Complaint c) {
    final color = ComplaintStyle.status(c.status);
    return InkWell(
      borderRadius: BorderRadius.circular(20),
      onTap: () => _openDetail(c),
      child: ComplaintCard(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(c.complaintId,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                          fontFamily: 'monospace',
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                          color: AppColors.primary)),
                ),
                const SizedBox(width: 6),
                if (c.isUnread) ...[
                  const ComplaintPill('New reply', Color(0xFFDB2777),
                      icon: Icons.mark_chat_unread_outlined),
                  const SizedBox(width: 6),
                ],
                ComplaintPill(c.status, color,
                    icon: ComplaintStyle.statusIcon(c.status)),
              ],
            ),
            const SizedBox(height: 10),
            Text(c.title,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w800,
                    color: AppColors.slate800)),
            const SizedBox(height: 4),
            Text(c.description,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 12, height: 1.4, color: AppColors.slate500)),
            const SizedBox(height: 12),
            Wrap(
              spacing: 12,
              runSpacing: 6,
              children: [
                _meta(Icons.label_outline, c.category),
                _meta(Icons.calendar_today_outlined,
                    ComplaintStyle.date(c.createdAt)),
                _meta(Icons.flag_outlined, c.priority,
                    color: ComplaintStyle.priority(c.priority)),
                if (c.isAnonymous)
                  _meta(Icons.person_off_outlined, 'Anonymous'),
                if (c.hasAttachment) _meta(Icons.attach_file, 'May attachment'),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _meta(IconData icon, String text, {Color? color}) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 13, color: color ?? AppColors.slate400),
          const SizedBox(width: 4),
          Text(text,
              style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: color ?? AppColors.slate500)),
        ],
      );

  Widget _emptyState(IconData icon, String title, String body) => ComplaintCard(
        padding: const EdgeInsets.symmetric(vertical: 36, horizontal: 24),
        child: Column(
          children: [
            Icon(icon, size: 44, color: AppColors.slate200),
            const SizedBox(height: 10),
            Text(title,
                style: TextStyle(
                    color: AppColors.slate500,
                    fontWeight: FontWeight.w800,
                    fontSize: 14)),
            const SizedBox(height: 6),
            Text(body,
                textAlign: TextAlign.center,
                style: TextStyle(
                    color: AppColors.slate400, fontSize: 12, height: 1.4)),
          ],
        ),
      );
}
