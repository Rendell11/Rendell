import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../services/session_service.dart';
import '../theme/app_theme.dart';
import '../widgets/wave_background.dart';
import '../screens/login_screen.dart';
import '../chat/chat_screen.dart';
import '../complaint/complaint_screen.dart';
import '../settings/app_settings.dart';
import '../settings/settings_screen.dart';

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
  ResidentModule('complaints', Icons.report_problem_outlined, Color(0xFFF59E0B)),
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
  }

  // Placeholder stats (wire to API later).
  int get _total => 0;
  int get _approved => 0;
  int get _pending => 0;
  int get _reservations => 0;

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

  /// Open a module. 'chat' and 'complaints' are live; the rest show the
  /// "coming soon" note.
  void _openModule(BuildContext context, ResidentModule m) {
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
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                children: [
                  _hero(context),
                  const SizedBox(height: 16),
                  _statCards(context),
                  const SizedBox(height: 20),
                  _sectionLabel(tr.quickAccess),
                  const SizedBox(height: 10),
                  _servicesGrid(context),
                  const SizedBox(height: 20),
                  _sectionLabel(tr.latestAnnouncements),
                  const SizedBox(height: 10),
                  _announcementsCard(),
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
              child: Image.asset('assets/barangaylogo.webp',
                  fit: BoxFit.cover),
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
                  child: const Text('99+',
                      textAlign: TextAlign.center,
                      style: TextStyle(
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
      shape:
          RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      onSelected: (v) {
        switch (v) {
          case 'profile':
            _openSettings(context);
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
                      fontWeight: FontWeight.w800,
                      color: AppColors.slate800)),
              if ((resident.email ?? '').isNotEmpty)
                Text(resident.email!,
                    style: TextStyle(
                        fontSize: 12, color: AppColors.slate500)),
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
        child: CircleAvatar(
          radius: 16,
          backgroundColor: AppColors.primary,
          child: Text(_initials,
              style: const TextStyle(
                  color: Colors.white,
                  fontSize: 12,
                  fontWeight: FontWeight.w800)),
        ),
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

  void _openNotifications(BuildContext context) {
    showModalBottomSheet(
      context: context,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (_) => Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Icon(Icons.notifications_outlined,
                  color: AppColors.primary, size: 20),
              const SizedBox(width: 8),
              Text(tr.notifications,
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
            ]),
            const SizedBox(height: 24),
            Center(
              child: Column(
                children: [
                  Icon(Icons.notifications_off_outlined,
                      size: 44, color: AppColors.slate200),
                  const SizedBox(height: 10),
                  Text(tr.noNotifications,
                      style: TextStyle(
                          color: AppColors.slate400,
                          fontWeight: FontWeight.w700)),
                ],
              ),
            ),
            const SizedBox(height: 12),
          ],
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
                  CircleAvatar(
                    radius: 18,
                    backgroundColor: Colors.white.withValues(alpha: .25),
                    child: Text(_initials,
                        style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                            fontSize: 13)),
                  ),
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
                  _openSettings(context);
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
          size: 21, color: iconColor ?? (active ? AppColors.primary : AppColors.slate500)),
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
          Container(
            width: 60,
            height: 60,
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: .20),
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: Colors.white.withValues(alpha: .30)),
            ),
            alignment: Alignment.center,
            child: Text(_initials,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 22,
                    fontWeight: FontWeight.w900)),
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
      _Stat(tr.totalRequests, _total, Icons.folder_open, const Color(0xFF6366F1)),
      _Stat(tr.approved, _approved, Icons.check_circle, const Color(0xFF16A34A)),
      _Stat(tr.pending, _pending, Icons.hourglass_top, const Color(0xFFF59E0B)),
      _Stat(tr.reservations, _reservations, Icons.build, const Color(0xFF8B5CF6)),
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
                  fontSize: 26,
                  fontWeight: FontWeight.w900,
                  color: s.color)),
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

  // ── Announcements (empty state until wired) ─────────────────────────────
  Widget _announcementsCard() {
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

  // ── Recent requests (empty state) ───────────────────────────────────────
  Widget _recentRequestsCard(BuildContext context) {
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
              onPressed: () => _soon(context, tr.moduleLabel('documents')),
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
          bar(tr.rejected, 0, const Color(0xFFEF4444)),
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
          Text(
              tr.helpBody,
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
