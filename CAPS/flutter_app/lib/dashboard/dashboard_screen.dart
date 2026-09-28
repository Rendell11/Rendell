import 'dart:async';

import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../services/session_service.dart';
import '../theme/app_theme.dart';
import '../widgets/wave_background.dart';
import '../screens/login_screen.dart';
import '../chat/chat_screen.dart';
import '../announcements/announcement_api.dart';
import '../announcements/announcement_detail_screen.dart';
import '../announcements/announcement_widgets.dart';
import '../announcements/announcements_screen.dart';
import '../complaint/complaint_screen.dart';
import '../blotter/blotter_screen.dart';
import '../certificate/certificate_api.dart';
import '../certificate/certificate_screen.dart';
import '../complaint/complaint_api.dart';
import '../disaster/alert_sound.dart';
import '../disaster/disaster_api.dart';
import '../disaster/disaster_screen.dart';
import '../household/household_screen.dart';
import '../main.dart' show appMessengerKey;
import '../notifications/notifications_api.dart';
import '../notifications/notifications_screen.dart';
import '../services/push_service.dart';
import '../officials/officials_screen.dart';
import '../settings/app_settings.dart';
import '../settings/settings_screen.dart';
import '../profile/profile_api.dart';
import '../profile/profile_avatar.dart';
import '../profile/profile_screen.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// RESIDENT DASHBOARD (landing page after login)
/// Ported from the SOE `user/dashboard.php` + top-bar navigation:
///   • Dark app bar: hamburger (opens module drawer) + barangay logo (left),
///     notifications + profile menu (right).
///   • Drawer: full list of resident modules (SOE sidebar).
///   • Body: hero banner, stat cards, quick services, announcements,
///     recent requests, request summary, help card.
///
/// Kept in its own `dashboard/` folder for isolated debugging. Stats & lists
/// render empty states until wired to the API.
/// ─────────────────────────────────────────────────────────────────────────

/// One resident module (used by both the drawer and the quick-access grid).
class ResidentModule {
  /// Stable id used for navigation; the visible [label] is localized.
  final String id;
  final IconData icon;
  final Color color;
  const ResidentModule(this.id, this.icon, this.color);

  String get label => tr.moduleLabel(id);
}

const List<ResidentModule> kModules = [
  ResidentModule('household', Icons.groups_outlined, Color(0xFF1D63DA)),
  ResidentModule('announcements', Icons.campaign_outlined, Color(0xFF0EA5E9)),
  ResidentModule('documents', Icons.description_outlined, Color(0xFF16A34A)),
  ResidentModule(
      'complaints', Icons.report_problem_outlined, Color(0xFFF59E0B)),
  ResidentModule('blotter', Icons.gavel_outlined, Color(0xFFE11D48)),
  ResidentModule('disaster', Icons.warning_amber_rounded, Color(0xFFEA580C)),
  ResidentModule('officials', Icons.badge_outlined, Color(0xFF6366F1)),
  ResidentModule('chat', Icons.chat_bubble_outline, Color(0xFFDB2777)),
];

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  Resident get resident => widget.resident;

  @override
  void initState() {
    super.initState();
    // Signed in: load this resident's saved preferences from the server and
    // keep future Settings changes in sync.
    AppSettings.instance.bindResident(resident.residentId);
    _loadPhoto();
    _loadAnnouncements();
    _loadRequests();
    _loadCounts();
    _loadAlerts();
    // New alerts / notifications show up without refreshing: check every
    // 15 s for alerts, every 30 s for the bell count.
    _poll = Timer.periodic(const Duration(seconds: 15), (t) {
      _loadAlerts();
      if (t.tick.isEven) _loadCounts();
    });
    // Push notifications (only when Firebase is set up — see PushService).
    PushService.instance
      ..onForeground = (m) {
        _loadCounts();
        final n = m.notification;
        if (n != null) {
          appMessengerKey.currentState?.showSnackBar(SnackBar(
            content: Text('${n.title ?? ''}\n${n.body ?? ''}'.trim()),
            action: SnackBarAction(
                label: tr.notifications,
                onPressed: () => _openNotifications(context)),
          ));
        }
      }
      ..onOpened = (_) {
        if (mounted) _openNotifications(context);
      }
      ..register();
  }

  Timer? _poll;
  int _unread = 0;

  @override
  void dispose() {
    _poll?.cancel();
    AlertSound.instance.stop();
    super.dispose();
  }

  int _openComplaints = 0;
  List<DisasterAlert> _activeAlerts = const [];

  /// Bell badge + "Open complaints" card.
  Future<void> _loadCounts() async {
    final api = NotificationsApi();
    final c = await api.count();
    api.dispose();
    final capi = ComplaintApi();
    final cl = await capi.list(resident.residentId);
    capi.dispose();
    if (!mounted) return;
    setState(() {
      if (c != null) _unread = c.unread;
      if (cl.ok && cl.data != null) {
        _openComplaints = cl.data!.stats.pending + cl.data!.stats.ongoing;
      }
    });
  }

  /// Active disaster alerts for the banner at the top. An alert this device
  /// has not seen yet plays its severity sound and pops up once.
  Future<void> _loadAlerts() async {
    final api = DisasterApi();
    final res = await api.load();
    api.dispose();
    if (!mounted || !res.ok) return;
    final active = res.data!.active;
    setState(() => _activeAlerts = active);
    final fresh = await AlertSound.instance.takeNew(active.map((a) => a.id));
    if (fresh.isEmpty || !mounted) return;
    final news = active.where((a) => fresh.contains(a.id)).toList()
      ..sort((a, b) => _rank(b.severity).compareTo(_rank(a.severity)));
    _announce(news.first, more: news.length - 1);
  }

  static int _rank(String? s) =>
      const {'Low': 1, 'Medium': 2, 'High': 3, 'Critical': 4}[s] ?? 0;

  void _announce(DisasterAlert a, {int more = 0}) {
    AlertSound.instance.play(a.severity);
    _loadCounts();
    final color = DisasterStyle.severity(a.severity);
    void open() => Navigator.of(context)
        .push(MaterialPageRoute(
            builder: (_) => DisasterScreen(resident: resident)))
        .then((_) => _loadAlerts());
    if (_rank(a.severity) >= 3) {
      // High / Critical: a pop-up the resident has to close.
      showDialog<void>(
        context: context,
        builder: (ctx) => AlertDialog(
          icon: Icon(DisasterStyle.icon(a.type), color: color, size: 44),
          title: Text(a.title, textAlign: TextAlign.center),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                  '${tr.activeAlert.toUpperCase()}'
                  '${a.severity == null ? '' : ' · ${tr.severityLabel(a.severity!).toUpperCase()}'}',
                  style: TextStyle(
                      color: color,
                      fontWeight: FontWeight.w900,
                      fontSize: 12,
                      letterSpacing: .8)),
              if (a.message != null) ...[
                const SizedBox(height: 10),
                Text(a.message!, textAlign: TextAlign.center),
              ],
              if (more > 0) ...[
                const SizedBox(height: 8),
                Text(tr.moreAlerts(more),
                    style: TextStyle(color: AppColors.slate500, fontSize: 12)),
              ],
            ],
          ),
          actions: [
            TextButton(
                onPressed: () {
                  AlertSound.instance.stop();
                  Navigator.pop(ctx);
                },
                child: Text(tr.close)),
            FilledButton(
                style: FilledButton.styleFrom(backgroundColor: color),
                onPressed: () {
                  AlertSound.instance.stop();
                  Navigator.pop(ctx);
                  open();
                },
                child: Text(tr.details)),
          ],
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        backgroundColor: color,
        content: Text('${tr.activeAlert}: ${a.title}'),
        action: SnackBarAction(
            label: tr.details, textColor: Colors.white, onPressed: open),
      ));
    }
  }

  Future<void> _refreshAll() async {
    await Future.wait([
      _loadRequests(),
      _loadCounts(),
      _loadAlerts(),
      _loadAnnouncements(),
    ]);
  }

  CertList? _requests;

  /// Document requests for the stat cards and "Recent Requests".
  Future<void> _loadRequests() async {
    final api = CertificateApi();
    final res = await api.list(resident.residentId);
    api.dispose();
    if (mounted && res.ok) setState(() => _requests = res.data);
  }

  Future<void> _openCertificates(BuildContext context,
      {bool openForm = false}) async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) =>
            CertificateScreen(resident: resident, openForm: openForm)));
    _loadRequests();
  }

  String? _photoUrl;
  List<Announcement> _latest = const [];
  bool _annLoading = true;

  /// Latest 3 announcements for the dashboard card.
  Future<void> _loadAnnouncements() async {
    final api = AnnouncementApi();
    final res = await api.list(resident.residentId);
    api.dispose();
    if (!mounted) return;
    setState(() {
      _annLoading = false;
      if (res.ok) _latest = (res.data ?? const []).take(3).toList();
    });
  }

  Future<void> _openAnnouncements(BuildContext context) async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => AnnouncementsScreen(resident: resident)));
    _loadAnnouncements(); // refresh "New" badges
  }

  Future<void> _openAnnouncement(BuildContext context, Announcement a) async {
    if (a.isNew) {
      final api = AnnouncementApi();
      await api.markRead(resident.residentId, a.id);
      api.dispose();
    }
    if (!context.mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => AnnouncementDetailScreen(announcement: a.asRead())));
    _loadAnnouncements();
  }

  /// Profile picture for the avatars (initials until it loads / if none).
  Future<void> _loadPhoto() async {
    final api = ProfileApi();
    final res = await api.get(resident.residentId);
    api.dispose();
    if (mounted && res.ok) setState(() => _photoUrl = res.data?.photoUrl);
  }

  Future<void> _openProfile(BuildContext context) async {
    final changed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => ProfileScreen(resident: resident)),
    );
    if (changed == true) _loadPhoto();
  }

  // Document requests (certificate.php); reservations are not built yet.
  int get _total => _requests?.total ?? 0;
  int get _approved => (_requests?.ready ?? 0) + (_requests?.released ?? 0);
  int get _pending => _requests?.pending ?? 0;
  int get _rejected => _requests?.rejected ?? 0;
  // Reservations card replaced by open complaints (Pending + Ongoing); there
  // is no equipment/facility module yet.

  String get _initials {
    final f = resident.firstName.isNotEmpty ? resident.firstName[0] : '';
    final l = resident.lastName.isNotEmpty ? resident.lastName[0] : '';
    final s = (f + l).toUpperCase();
    return s.isEmpty ? '?' : s;
  }

  String get _greeting {
    final h = DateTime.now().hour;
    if (h < 12) return tr.goodMorning;
    if (h < 17) return tr.goodAfternoon;
    return tr.goodEvening;
  }

  Future<void> _logout(BuildContext context) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(tr.logoutConfirmTitle),
        content: Text(tr.logoutConfirmBody),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: Text(tr.cancel)),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(tr.logout)),
        ],
      ),
    );
    if (ok != true) return;
    await SessionService().clear();
    AppSettings.instance.unbindResident();
    if (!context.mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (_) => false,
    );
  }

  void _soon(BuildContext context, String feature) =>
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(tr.comingSoon(feature))),
      );

  void _openSettings(BuildContext context) {
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => SettingsScreen(resident: resident)),
    );
  }

  /// Open a module. Announcements, officials, complaints and chat are live;
  /// the rest show the "coming soon" note.
  void _openModule(BuildContext context, ResidentModule m) {
    if (m.id == 'announcements') {
      _openAnnouncements(context);
      return;
    }
    if (m.id == 'documents') {
      _openCertificates(context);
      return;
    }
    if (m.id == 'disaster') {
      Navigator.of(context)
          .push(MaterialPageRoute(
              builder: (_) => DisasterScreen(resident: resident)))
          .then((_) => _loadAlerts());
      return;
    }
    if (m.id == 'blotter') {
      Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => BlotterScreen(resident: resident)),
      );
      return;
    }
    if (m.id == 'household') {
      Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => HouseholdScreen(resident: resident)),
      );
      return;
    }
    if (m.id == 'officials') {
      Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => const OfficialsScreen()),
      );
      return;
    }
    if (m.id == 'complaints') {
      Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => ComplaintScreen(resident: resident)),
      );
      return;
    }
    if (m.id == 'chat') {
      Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => ChatScreen(resident: resident)),
      );
      return;
    }
    _soon(context, m.label);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      drawer: _drawer(context),
      appBar: _appBar(context),
      body: WaveBackground(
        child: SafeArea(
          top: false,
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: RefreshIndicator(
                color: AppColors.primary,
                onRefresh: _refreshAll,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                  children: [
                    for (final a in _activeAlerts) ...[
                      _alertBanner(context, a),
                      const SizedBox(height: 12),
                    ],
                    _hero(context),
                    const SizedBox(height: 16),
                    _statCards(context),
                    const SizedBox(height: 20),
                    _sectionLabel(tr.quickAccess),
                    const SizedBox(height: 10),
                    _servicesGrid(context),
                    const SizedBox(height: 20),
                    Row(children: [
                      Expanded(child: _sectionLabel(tr.latestAnnouncements)),
                      if (_latest.isNotEmpty)
                        TextButton(
                          onPressed: () => _openAnnouncements(context),
                          child: Text(tr.viewAll),
                        ),
                    ]),
                    const SizedBox(height: 10),
                    _announcementsCard(context),
                    const SizedBox(height: 16),
                    _sectionLabel(tr.recentRequests),
                    const SizedBox(height: 10),
                    _recentRequestsCard(context),
                    const SizedBox(height: 16),
                    _sectionLabel(tr.requestSummary),
                    const SizedBox(height: 10),
                    _summaryCard(),
                    const SizedBox(height: 16),
                    _helpCard(),
                    const SizedBox(height: 24),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  // ── APP BAR ─────────────────────────────────────────────────────────────
  PreferredSizeWidget _appBar(BuildContext context) {
    return AppBar(
      backgroundColor: AppColors.appBar,
      elevation: 0,
      titleSpacing: 0,
      leadingWidth: 44,
      leading: Builder(
        builder: (ctx) => IconButton(
          icon: const Icon(Icons.menu, color: Colors.white),
          onPressed: () => Scaffold.of(ctx).openDrawer(),
          tooltip: tr.modules,
        ),
      ),
      title: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: const BoxDecoration(
              color: Colors.white,
              shape: BoxShape.circle,
            ),
            padding: const EdgeInsets.all(2),
            child: ClipOval(
              child: Image.asset('assets/barangaylogo.webp', fit: BoxFit.cover),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Text('Barangay Biñang 2nd',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                        color: Colors.white,
                        fontSize: 14,
                        fontWeight: FontWeight.w800)),
                Text(tr.residentPortal,
                    style: const TextStyle(
                        color: Colors.white70,
                        fontSize: 10,
                        fontWeight: FontWeight.w600)),
              ],
            ),
          ),
        ],
      ),
      actions: [
        // Notifications with badge
        IconButton(
          onPressed: () => _openNotifications(context),
          tooltip: tr.notifications,
          icon: Stack(
            clipBehavior: Clip.none,
            children: [
              const Icon(Icons.notifications_outlined, color: Colors.white),
              if (_unread > 0)
                Positioned(
                  right: -4,
                  top: -4,
                  child: Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
                    decoration: BoxDecoration(
                        color: const Color(0xFFEF4444),
                        borderRadius: BorderRadius.circular(50)),
                    constraints: const BoxConstraints(minWidth: 16),
                    child: Text(_unread > 99 ? '99+' : '$_unread',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                            color: Colors.white,
                            fontSize: 8,
                            fontWeight: FontWeight.w800)),
                  ),
                ),
            ],
          ),
        ),
        // Profile menu
        _profileMenu(context),
        const SizedBox(width: 6),
      ],
    );
  }

  Widget _profileMenu(BuildContext context) {
    return PopupMenuButton<String>(
      tooltip: tr.profile,
      offset: const Offset(0, 48),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      onSelected: (v) {
        switch (v) {
          case 'profile':
            _openProfile(context);
            break;
          case 'settings':
            _openSettings(context);
            break;
          case 'logout':
            _logout(context);
            break;
        }
      },
      itemBuilder: (_) => [
        PopupMenuItem(
          enabled: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(resident.fullName,
                  style: TextStyle(
                      fontWeight: FontWeight.w800, color: AppColors.slate800)),
              if ((resident.email ?? '').isNotEmpty)
                Text(resident.email!,
                    style: TextStyle(fontSize: 12, color: AppColors.slate500)),
            ],
          ),
        ),
        const PopupMenuDivider(),
        _menuItem('profile', Icons.person_outline, tr.myProfile),
        _menuItem('settings', Icons.settings_outlined, tr.settings),
        const PopupMenuDivider(),
        PopupMenuItem(
          value: 'logout',
          child: Row(children: [
            const Icon(Icons.logout, size: 18, color: AppColors.danger),
            const SizedBox(width: 10),
            Text(tr.logout,
                style: const TextStyle(
                    color: AppColors.danger, fontWeight: FontWeight.w700)),
          ]),
        ),
      ],
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child:
            ProfileAvatar(initials: _initials, photoUrl: _photoUrl, size: 32),
      ),
    );
  }

  PopupMenuItem<String> _menuItem(String v, IconData icon, String label) =>
      PopupMenuItem(
        value: v,
        child: Row(children: [
          Icon(icon, size: 18, color: AppColors.slate500),
          const SizedBox(width: 10),
          Text(label, style: TextStyle(color: AppColors.slate800)),
        ]),
      );

  Future<void> _openNotifications(BuildContext context) async {
    await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => NotificationsScreen(resident: resident)));
    _refreshAll();
  }

  /// Red banner for an active disaster alert (tap → Disaster Alerts).
  Widget _alertBanner(BuildContext context, DisasterAlert a) {
    final c = DisasterStyle.severity(a.severity);
    return Material(
      color: c,
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: () => Navigator.of(context)
            .push(MaterialPageRoute(
                builder: (_) => DisasterScreen(resident: resident)))
            .then((_) => _loadAlerts()),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .2),
                  borderRadius: BorderRadius.circular(12)),
              child: Icon(DisasterStyle.icon(a.type), color: Colors.white),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                      '${tr.activeAlert.toUpperCase()}'
                      '${a.severity == null ? '' : ' · ${tr.severityLabel(a.severity!).toUpperCase()}'}',
                      style: TextStyle(
                          color: Colors.white.withValues(alpha: .85),
                          fontSize: 10.5,
                          letterSpacing: .8,
                          fontWeight: FontWeight.w900)),
                  Text(a.title,
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 15,
                          fontWeight: FontWeight.w900)),
                  if (a.evacuation != null)
                    Text('${tr.evacuationCenter}: ${a.evacuation}',
                        style: TextStyle(
                            color: Colors.white.withValues(alpha: .9),
                            fontSize: 12)),
                ],
              ),
            ),
            const Icon(Icons.chevron_right, color: Colors.white),
          ]),
        ),
      ),
    );
  }

  // ── DRAWER (module list) ────────────────────────────────────────────────
  Widget _drawer(BuildContext context) {
    return Drawer(
      child: Column(
        children: [
          // Drawer header
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(20, 56, 20, 20),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [AppColors.heading2, AppColors.primary],
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  Container(
                    width: 48,
                    height: 48,
                    decoration: const BoxDecoration(
                      color: Colors.white,
                      shape: BoxShape.circle,
                    ),
                    padding: const EdgeInsets.all(2),
                    child: ClipOval(
                      child: Image.asset('assets/barangaylogo.webp',
                          fit: BoxFit.cover),
                    ),
                  ),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text('Barangay\nBiñang 2nd',
                        style: TextStyle(
                            color: Colors.white,
                            fontSize: 15,
                            height: 1.15,
                            fontWeight: FontWeight.w900)),
                  ),
                ]),
                const SizedBox(height: 16),
                Row(children: [
                  ProfileAvatar(
                      initials: _initials,
                      photoUrl: _photoUrl,
                      size: 36,
                      onGradient: true),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(resident.fullName,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.w700,
                                fontSize: 13)),
                        Text(tr.activeResident,
                            style: TextStyle(
                                color: Colors.white.withValues(alpha: .7),
                                fontSize: 11)),
                      ],
                    ),
                  ),
                ]),
              ],
            ),
          ),
          // Module list
          Expanded(
            child: ListView(
              padding: const EdgeInsets.symmetric(vertical: 8),
              children: [
                _drawerItem(context, Icons.dashboard_outlined, tr.dashboard,
                    active: true, onTap: () => Navigator.pop(context)),
                const Divider(height: 1),
                for (final m in kModules)
                  _drawerItem(context, m.icon, m.label, iconColor: m.color,
                      onTap: () {
                    Navigator.pop(context);
                    _openModule(context, m);
                  }),
                const Divider(height: 1),
                _drawerItem(context, Icons.person_outline, tr.myProfile,
                    onTap: () {
                  Navigator.pop(context);
                  _openProfile(context);
                }),
                _drawerItem(context, Icons.settings_outlined, tr.settings,
                    onTap: () {
                  Navigator.pop(context);
                  _openSettings(context);
                }),
                _drawerItem(context, Icons.logout, tr.logout,
                    iconColor: AppColors.danger, onTap: () {
                  Navigator.pop(context);
                  _logout(context);
                }),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _drawerItem(BuildContext context, IconData icon, String label,
      {bool active = false, Color? iconColor, VoidCallback? onTap}) {
    return ListTile(
      dense: true,
      leading: Icon(icon,
          size: 21,
          color:
              iconColor ?? (active ? AppColors.primary : AppColors.slate500)),
      title: Text(label,
          style: TextStyle(
              fontSize: 14,
              fontWeight: active ? FontWeight.w800 : FontWeight.w600,
              color: active ? AppColors.primary : AppColors.slate800)),
      tileColor: active ? AppColors.primary.withValues(alpha: .06) : null,
      onTap: onTap,
    );
  }

  // ── Hero welcome banner ─────────────────────────────────────────────────
  Widget _hero(BuildContext context) {
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
      child: Row(
        children: [
          GestureDetector(
            onTap: () => _openProfile(context),
            child: ProfileAvatar(
                initials: _initials,
                photoUrl: _photoUrl,
                size: 60,
                onGradient: true),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr.residentAccount.toUpperCase(),
                    style: TextStyle(
                        color: Colors.white.withValues(alpha: .6),
                        fontSize: 10,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 1.5)),
                const SizedBox(height: 2),
                Text('$_greeting, ${resident.firstName}!',
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                        height: 1.1)),
                if ((resident.email ?? '').isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(resident.email!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                          color: Colors.white.withValues(alpha: .65),
                          fontSize: 13,
                          fontWeight: FontWeight.w500)),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ── Stat cards ──────────────────────────────────────────────────────────
  Widget _statCards(BuildContext context) {
    final cards = <_Stat>[
      _Stat(
          tr.totalRequests, _total, Icons.folder_open, const Color(0xFF6366F1)),
      _Stat(
          tr.approved, _approved, Icons.check_circle, const Color(0xFF16A34A)),
      _Stat(tr.pending, _pending, Icons.hourglass_top, const Color(0xFFF59E0B)),
      _Stat(tr.openComplaints, _openComplaints, Icons.report_problem_outlined,
          const Color(0xFF8B5CF6)),
    ];
    // Height grows with the text size (Settings → Text size) so the card
    // never overflows.
    final scale = MediaQuery.textScalerOf(context).scale(1);
    return GridView(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        mainAxisSpacing: 12,
        crossAxisSpacing: 12,
        mainAxisExtent: 74 + 40 * scale,
      ),
      children: [for (final s in cards) _statCard(context, s)],
    );
  }

  Widget _statCard(BuildContext context, _Stat s) {
    return _white(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Flexible(
                child: Text(s.label.toUpperCase(),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                        fontSize: 10,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 1,
                        color: AppColors.slate400)),
              ),
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                    color: s.color.withValues(alpha: .12),
                    borderRadius: BorderRadius.circular(10)),
                child: Icon(s.icon, size: 18, color: s.color),
              ),
            ],
          ),
          Text('${s.value}',
              style: TextStyle(
                  fontSize: 26, fontWeight: FontWeight.w900, color: s.color)),
        ],
      ),
    );
  }

  // ── Quick services grid ─────────────────────────────────────────────────
  Widget _servicesGrid(BuildContext context) {
    // Show the first 6 as quick access; the rest live in the drawer.
    final quick = kModules.take(6).toList();
    final scale = MediaQuery.textScalerOf(context).scale(1);
    return GridView(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        mainAxisSpacing: 12,
        crossAxisSpacing: 12,
        mainAxisExtent: 76 + 34 * scale,
      ),
      children: [
        for (final s in quick)
          InkWell(
            borderRadius: BorderRadius.circular(18),
            onTap: () => _openModule(context, s),
            child: _white(
              padding: const EdgeInsets.all(10),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                        color: s.color.withValues(alpha: .12),
                        borderRadius: BorderRadius.circular(14)),
                    child: Icon(s.icon, color: s.color, size: 22),
                  ),
                  const SizedBox(height: 8),
                  Text(s.label,
                      textAlign: TextAlign.center,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                          fontSize: 11,
                          height: 1.15,
                          fontWeight: FontWeight.w700,
                          color: AppColors.slate800)),
                ],
              ),
            ),
          ),
      ],
    );
  }

  // ── Latest announcements (3 newest; empty state when none) ─────────────
  Widget _announcementsCard(BuildContext context) {
    if (_annLoading) {
      return _white(
        child: Center(
            child: Padding(
          padding: const EdgeInsets.all(12),
          child: CircularProgressIndicator(color: AppColors.primary),
        )),
      );
    }
    if (_latest.isNotEmpty) {
      return _white(
        padding: EdgeInsets.zero,
        child: Material(
          type: MaterialType.transparency,
          child: Column(children: [
            for (var i = 0; i < _latest.length; i++) ...[
              if (i > 0) Divider(height: 1, color: AppColors.border),
              _announcementRow(context, _latest[i]),
            ],
          ]),
        ),
      );
    }
    return _white(
      padding: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 36),
        child: Column(
          children: [
            Icon(Icons.campaign_outlined, size: 44, color: AppColors.slate200),
            const SizedBox(height: 10),
            Text(tr.noAnnouncements,
                style: TextStyle(
                    color: AppColors.slate400,
                    fontWeight: FontWeight.w700,
                    fontSize: 13)),
          ],
        ),
      ),
    );
  }

  Widget _announcementRow(BuildContext context, Announcement a) {
    final color = AnnouncementStyle.category(a.category);
    return InkWell(
      onTap: () => _openAnnouncement(context, a),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: SizedBox(
                width: 56,
                height: 56,
                child: a.cover != null
                    ? AnnouncementImage(a.cover!, height: 56)
                    : Container(
                        color: color.withValues(alpha: .12),
                        child: Icon(AnnouncementStyle.icon(a.category),
                            color: color),
                      ),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(spacing: 6, runSpacing: 4, children: [
                    AnnouncementPill(
                        tr.announcementCategory(a.category), color),
                    if (a.isNew)
                      AnnouncementPill(tr.newLabel, const Color(0xFFDB2777)),
                  ]),
                  const SizedBox(height: 6),
                  Text(a.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                          fontSize: 13,
                          height: 1.25,
                          fontWeight: FontWeight.w800,
                          color: AppColors.slate800)),
                  const SizedBox(height: 3),
                  Text(tr.postedOn(AnnouncementStyle.date(a.datePosted)),
                      style:
                          TextStyle(fontSize: 11, color: AppColors.slate400)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Recent requests (empty state) ───────────────────────────────────────
  Widget _recentRequestsCard(BuildContext context) {
    final recent = (_requests?.requests ?? const <CertRequest>[]).take(3);
    if (recent.isNotEmpty) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final r in recent) ...[
            CertRequestTile(
                request: r, onTap: () => _openCertificates(context)),
            const SizedBox(height: 10),
          ],
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              onPressed: () => _openCertificates(context),
              icon: const Icon(Icons.arrow_forward, size: 16),
              label: Text(tr.viewAllRequests),
            ),
          ),
        ],
      );
    }
    return _white(
      padding: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 32),
        child: Column(
          children: [
            Icon(Icons.inbox_outlined, size: 44, color: AppColors.slate200),
            const SizedBox(height: 10),
            Text(tr.noRequests,
                style: TextStyle(
                    color: AppColors.slate400,
                    fontWeight: FontWeight.w700,
                    fontSize: 13)),
            const SizedBox(height: 8),
            TextButton.icon(
              onPressed: () => _openCertificates(context, openForm: true),
              icon: const Icon(Icons.add, size: 16),
              label: Text(tr.makeFirstRequest),
            ),
          ],
        ),
      ),
    );
  }

  // ── Request summary bars ────────────────────────────────────────────────
  Widget _summaryCard() {
    Widget bar(String label, int val, Color color) {
      final pct = _total > 0 ? (val / _total * 100).round() : 0;
      return Padding(
        padding: const EdgeInsets.only(bottom: 14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(children: [
                  Container(
                      width: 8,
                      height: 8,
                      decoration:
                          BoxDecoration(color: color, shape: BoxShape.circle)),
                  const SizedBox(width: 8),
                  Text(label,
                      style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w800,
                          color: AppColors.slate800)),
                ]),
                Text('$val ($pct%)',
                    style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w800,
                        color: color)),
              ],
            ),
            const SizedBox(height: 6),
            ClipRRect(
              borderRadius: BorderRadius.circular(50),
              child: LinearProgressIndicator(
                value: pct / 100,
                minHeight: 6,
                backgroundColor: AppColors.muted,
                valueColor: AlwaysStoppedAnimation(color),
              ),
            ),
          ],
        ),
      );
    }

    return _white(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          bar(tr.approved, _approved, const Color(0xFF16A34A)),
          bar(tr.pending, _pending, const Color(0xFFF59E0B)),
          bar(tr.rejected, _rejected, const Color(0xFFEF4444)),
          Divider(color: AppColors.bgBottom, height: 8),
          const SizedBox(height: 8),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(tr.total.toUpperCase(),
                  style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w900,
                      letterSpacing: 1.5,
                      color: AppColors.slate400)),
              Text('$_total',
                  style: TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w900,
                      color: AppColors.slate800)),
            ],
          ),
        ],
      ),
    );
  }

  // ── Help card ───────────────────────────────────────────────────────────
  Widget _helpCard() {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF0F172A), Color(0xFF1E293B)],
        ),
        borderRadius: BorderRadius.circular(24),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                  color: AppColors.primary,
                  borderRadius: BorderRadius.circular(12)),
              child: const Icon(Icons.support_agent,
                  color: Colors.white, size: 20),
            ),
            const SizedBox(width: 12),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(tr.barangayOffice.toUpperCase(),
                    style: TextStyle(
                        color: Colors.white.withValues(alpha: .5),
                        fontSize: 9,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 1.5)),
                Text(tr.needAssistance,
                    style: const TextStyle(
                        color: Colors.white,
                        fontSize: 14,
                        fontWeight: FontWeight.w900)),
              ],
            ),
          ]),
          const SizedBox(height: 14),
          Text(tr.helpBody,
              style: TextStyle(
                  color: Colors.white.withValues(alpha: .6),
                  fontSize: 12,
                  height: 1.5,
                  fontWeight: FontWeight.w500)),
          const SizedBox(height: 14),
          _helpRow(Icons.call, '(044) 123-4567'),
          const SizedBox(height: 8),
          _helpRow(Icons.schedule, tr.officeHours),
        ],
      ),
    );
  }

  Widget _helpRow(IconData icon, String text) => Row(
        children: [
          Icon(icon, size: 15, color: AppColors.accent),
          const SizedBox(width: 8),
          Text(text,
              style: TextStyle(
                  color: Colors.white.withValues(alpha: .7),
                  fontSize: 12,
                  fontWeight: FontWeight.w700)),
        ],
      );

  // ── Shared helpers ──────────────────────────────────────────────────────
  Widget _sectionLabel(String s) => Padding(
        padding: const EdgeInsets.only(left: 4),
        child: Text(s.toUpperCase(),
            style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w900,
                letterSpacing: .8,
                color: AppColors.heading2)),
      );

  Widget _white({required Widget child, EdgeInsetsGeometry? padding}) =>
      Container(
        padding: padding ?? const EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(
              color: AppColors.isDark ? AppColors.border : AppColors.bgBottom),
          boxShadow: [
            BoxShadow(
                color: AppColors.primary.withValues(alpha: .06),
                blurRadius: 18,
                offset: const Offset(0, 6)),
          ],
        ),
        child: child,
      );
}

class _Stat {
  final String label;
  final int value;
  final IconData icon;
  final Color color;
  const _Stat(this.label, this.value, this.icon, this.color);
}
