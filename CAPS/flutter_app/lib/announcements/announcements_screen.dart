import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'announcement_api.dart';
import 'announcement_detail_screen.dart';
import 'announcement_widgets.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// ANNOUNCEMENTS — ported from SOE `user/announcements.php`.
///   • Published announcements from the admin, Emergency Notices on top
///   • Search + category filter, "New" badge until opened
///   • Cover image, schedule and "Ended" tag; tap for the full post
/// Talks to `user/backend/announcements.php`. Kept in its own
/// `announcements/` folder.
/// ─────────────────────────────────────────────────────────────────────────
class AnnouncementsScreen extends StatefulWidget {
  const AnnouncementsScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<AnnouncementsScreen> createState() => _AnnouncementsScreenState();
}

class _AnnouncementsScreenState extends State<AnnouncementsScreen> {
  final _api = AnnouncementApi();
  final _search = TextEditingController();

  List<Announcement> _items = const [];
  bool _loading = true;
  String? _error;
  String _category = ''; // '' = all

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
    final res = await _api.list(widget.resident.residentId);
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = res.ok ? null : res.message;
      if (res.ok) _items = res.data ?? const [];
    });
  }

  List<String> get _categories {
    final seen = <String>[];
    for (final a in _items) {
      if (!seen.contains(a.category)) seen.add(a.category);
    }
    return seen;
  }

  List<Announcement> get _visible {
    final q = _search.text.trim().toLowerCase();
    return _items.where((a) {
      final okCat = _category.isEmpty || a.category == _category;
      final okQ = q.isEmpty ||
          '${a.title} ${a.details} ${a.category}'.toLowerCase().contains(q);
      return okCat && okQ;
    }).toList();
  }

  Future<void> _open(Announcement a) async {
    if (a.isNew) {
      _api.markRead(widget.resident.residentId, a.id);
      setState(() => _items = [
            for (final x in _items) x.id == a.id ? x.asRead() : x,
          ]);
    }
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => AnnouncementDetailScreen(announcement: a.asRead())));
  }

  @override
  Widget build(BuildContext context) {
    final newCount = _items.where((a) => a.isNew).length;
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: AppBar(
        backgroundColor: AppColors.appBar,
        foregroundColor: Colors.white,
        elevation: 0,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(tr.moduleLabel('announcements'),
                style:
                    const TextStyle(fontSize: 15, fontWeight: FontWeight.w800)),
            Text(
                newCount > 0
                    ? tr.newAnnouncementsCount(newCount)
                    : tr.announcementsSubtitle,
                style: const TextStyle(
                    fontSize: 11,
                    color: Colors.white70,
                    fontWeight: FontWeight.w500)),
          ],
        ),
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
                  constraints: const BoxConstraints(maxWidth: 640),
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
                    children: [
                      TextField(
                        controller: _search,
                        onChanged: (_) => setState(() {}),
                        decoration: AppTheme.field(tr.searchAnnouncements,
                            icon: Icons.search,
                            suffix: _search.text.isEmpty
                                ? null
                                : IconButton(
                                    icon: const Icon(Icons.close, size: 18),
                                    onPressed: () => setState(_search.clear),
                                  )),
                      ),
                      const SizedBox(height: 10),
                      _chips(),
                      const SizedBox(height: 14),
                      ..._body(),
                    ],
                  ),
                ),
              ),
            ),
    );
  }

  Widget _chips() {
    Widget chip(String value, String label) {
      final selected = _category == value;
      final color =
          value.isEmpty ? AppColors.primary : AnnouncementStyle.category(value);
      return Padding(
        padding: const EdgeInsets.only(right: 8),
        child: ChoiceChip(
          label: Text(label),
          selected: selected,
          showCheckmark: false,
          onSelected: (_) => setState(() => _category = value),
          selectedColor: color.withValues(alpha: .15),
          backgroundColor: AppColors.surface,
          side: BorderSide(color: AppColors.border),
          labelStyle: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w800,
              color: selected ? color : AppColors.slate500),
        ),
      );
    }

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(children: [
        chip('', tr.all),
        for (final c in _categories) chip(c, tr.announcementCategory(c)),
      ]),
    );
  }

  List<Widget> _body() {
    if (_error != null) return [_empty(Icons.error_outline, _error!)];
    if (_items.isEmpty) {
      return [_empty(Icons.campaign_outlined, tr.noAnnouncements)];
    }
    final list = _visible;
    if (list.isEmpty) return [_empty(Icons.search_off, tr.noMatchesBody)];
    return [
      for (final a in list)
        Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: _card(a),
        ),
    ];
  }

  Widget _card(Announcement a) {
    final color = AnnouncementStyle.category(a.category);
    final schedule = AnnouncementStyle.schedule(a);
    return Material(
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(20),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _open(a),
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            border: Border.all(
                color: a.isEmergency && !a.isEnded
                    ? color.withValues(alpha: .6)
                    : AppColors.border,
                width: a.isEmergency && !a.isEnded ? 1.6 : 1),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (a.cover != null)
                ClipRRect(
                  borderRadius:
                      const BorderRadius.vertical(top: Radius.circular(19)),
                  child: AnnouncementImage(a.cover!, height: 170),
                ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(spacing: 6, runSpacing: 6, children: [
                      AnnouncementPill(
                          tr.announcementCategory(a.category), color,
                          icon: AnnouncementStyle.icon(a.category)),
                      if (a.isNew)
                        AnnouncementPill(tr.newLabel, const Color(0xFFDB2777)),
                      if (a.isEnded)
                        AnnouncementPill(tr.endedLabel, AppColors.slate400),
                    ]),
                    const SizedBox(height: 10),
                    Text(a.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                            fontSize: 15.5,
                            height: 1.25,
                            fontWeight: FontWeight.w800,
                            color: AppColors.slate800)),
                    const SizedBox(height: 6),
                    Text(a.details,
                        maxLines: 3,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                            fontSize: 12.5,
                            height: 1.45,
                            color: AppColors.slate500)),
                    const SizedBox(height: 12),
                    Wrap(spacing: 14, runSpacing: 6, children: [
                      _meta(Icons.schedule,
                          tr.postedOn(AnnouncementStyle.date(a.datePosted))),
                      if (schedule.isNotEmpty)
                        _meta(Icons.event_outlined, schedule),
                      if (a.attachments.isNotEmpty)
                        _meta(Icons.attach_file,
                            tr.attachmentsCount(a.attachments.length)),
                    ]),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _meta(IconData icon, String text) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 13, color: AppColors.slate400),
          const SizedBox(width: 4),
          Flexible(
            child: Text(text,
                style: TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    color: AppColors.slate500)),
          ),
        ],
      );

  Widget _empty(IconData icon, String text) => Container(
        padding: const EdgeInsets.symmetric(vertical: 36, horizontal: 24),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: AppColors.border),
        ),
        child: Column(children: [
          Icon(icon, size: 44, color: AppColors.slate200),
          const SizedBox(height: 10),
          Text(text,
              textAlign: TextAlign.center,
              style: TextStyle(
                  color: AppColors.slate500, fontWeight: FontWeight.w700)),
        ]),
      );
}
