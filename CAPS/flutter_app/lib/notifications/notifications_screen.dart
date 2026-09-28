import 'package:flutter/material.dart';

import '../announcements/announcement_api.dart';
import '../announcements/announcement_detail_screen.dart';
import '../blotter/blotter_detail_screen.dart';
import '../certificate/certificate_detail_screen.dart';
import '../complaint/complaint_api.dart';
import '../complaint/complaint_detail_screen.dart';
import '../complaint/complaint_widgets.dart';
import '../disaster/disaster_screen.dart';
import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'notifications_api.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// NOTIFICATIONS — updates on document requests and complaints (from the
/// admin), new announcements, disaster alerts, blotter case updates and
/// hearing reminders. Tapping one opens the related screen and marks it read.
///
/// Talks to `user/backend/notifications.php`. Kept in its own folder.
/// ─────────────────────────────────────────────────────────────────────────
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  final _api = NotificationsApi();
  List<AppNotification>? _items;
  String? _error;
  bool _loading = true;

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
    final res = await _api.list();
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (res.ok) {
        _items = res.data;
        _error = null;
      } else {
        _error = res.message;
      }
    });
  }

  Future<void> _markAll() async {
    setState(() => _items = _items?.map((n) => n.read()).toList());
    await _api.markAllRead();
  }

  Future<void> _open(AppNotification n) async {
    if (!n.isRead) {
      setState(() =>
          _items = _items?.map((x) => x.key == n.key ? x.read() : x).toList());
      _api.markRead(n.key);
    }
    final r = widget.resident;
    final id = n.refId;
    Widget? page;
    switch (n.refTable) {
      case 'document_requests':
        if (id != null) {
          page = CertificateDetailScreen(resident: r, requestId: id);
        }
      case 'blotter':
        if (id != null) page = BlotterDetailScreen(resident: r, caseId: id);
      case 'disaster_alerts':
        page = DisasterScreen(resident: r);
      case 'complaints':
        if (id != null) {
          final api = ComplaintApi();
          final res = await api.detail(r.residentId, id);
          api.dispose();
          if (res.ok && res.data != null) {
            page = ComplaintDetailScreen(resident: r, complaint: res.data!);
          }
        }
      case 'announcements':
        final api = AnnouncementApi();
        final res = await api.list(r.residentId);
        api.dispose();
        final a = (res.data ?? const <Announcement>[])
            .where((x) => x.id == id)
            .firstOrNull;
        if (a != null) {
          api.markRead(r.residentId, a.id);
          page = AnnouncementDetailScreen(announcement: a.asRead());
        }
    }
    if (page == null || !mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => page!));
  }

  @override
  Widget build(BuildContext context) {
    final unread = _items?.where((n) => !n.isRead).length ?? 0;
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: complaintAppBar(tr.notifications, tr.noNotificationsBody,
          icon: Icons.notifications_outlined,
          actions: [
            if (unread > 0)
              IconButton(
                tooltip: tr.markAllRead,
                onPressed: _markAll,
                icon: const Icon(Icons.done_all),
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
    final items = _items;
    if (items == null) {
      return [
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: AppColors.dangerBg,
            border: Border.all(color: AppColors.dangerBorder),
            borderRadius: BorderRadius.circular(14),
          ),
          child: Text(_error ?? tr.notificationsLoadFailed,
              style: TextStyle(
                  color: AppColors.dangerText, fontWeight: FontWeight.w700)),
        ),
      ];
    }
    if (items.isEmpty) {
      return [
        const SizedBox(height: 60),
        Icon(Icons.notifications_off_outlined,
            size: 56, color: AppColors.slate400),
        const SizedBox(height: 12),
        Text(tr.noNotifications,
            textAlign: TextAlign.center,
            style: TextStyle(
                fontSize: 15,
                fontWeight: FontWeight.w900,
                color: AppColors.slate800)),
        const SizedBox(height: 6),
        Text(tr.noNotificationsBody,
            textAlign: TextAlign.center,
            style: TextStyle(
                fontSize: 12.5, height: 1.4, color: AppColors.slate500)),
      ];
    }
    return [
      for (final n in items) ...[
        _tile(n),
        const SizedBox(height: 8),
      ],
    ];
  }

  Widget _tile(AppNotification n) {
    final (icon, color) = n.look;
    return Material(
      color: n.isRead ? AppColors.surface : color.withValues(alpha: .07),
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => _open(n),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
                color:
                    n.isRead ? AppColors.border : color.withValues(alpha: .35)),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                    color: color.withValues(alpha: .13),
                    borderRadius: BorderRadius.circular(12)),
                child: Icon(icon, color: color, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Expanded(
                        child: Text(n.title,
                            style: TextStyle(
                                fontSize: 13.5,
                                fontWeight: n.isRead
                                    ? FontWeight.w700
                                    : FontWeight.w900,
                                color: AppColors.slate800)),
                      ),
                      if (!n.isRead)
                        Container(
                          width: 9,
                          height: 9,
                          margin: const EdgeInsets.only(left: 6, top: 4),
                          decoration: BoxDecoration(
                              color: color, shape: BoxShape.circle),
                        ),
                    ]),
                    if (n.message.isNotEmpty) ...[
                      const SizedBox(height: 3),
                      Text(n.message,
                          maxLines: 3,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                              fontSize: 12.5,
                              height: 1.35,
                              color: AppColors.slate500)),
                    ],
                    const SizedBox(height: 5),
                    Text(
                        n.source == 'rn'
                            ? n.when
                            : '${tr.notifSource(n.source)} · ${n.when}',
                        style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: AppColors.slate400)),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
