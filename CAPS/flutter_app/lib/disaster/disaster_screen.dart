import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

import '../complaint/complaint_widgets.dart';
import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'disaster_api.dart';
import 'hazard_map.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// ALERTS & HAZARD MAP — from the admin "Disaster and Risk Map".
///   • Alerts: active alerts (message, affected area, evacuation center),
///     then the last 30 days
///   • Hazard Map: flood / fire / … zones, Safe Points, active alert areas
///     and the resident's home pin
///
/// Talks to `user/backend/disaster.php`. Kept in its own `disaster/` folder.
/// ─────────────────────────────────────────────────────────────────────────
class DisasterScreen extends StatefulWidget {
  const DisasterScreen(
      {super.key, required this.resident, this.showMap = false});

  final Resident resident;
  final bool showMap;

  @override
  State<DisasterScreen> createState() => _DisasterScreenState();
}

class _DisasterScreenState extends State<DisasterScreen>
    with SingleTickerProviderStateMixin {
  final _api = DisasterApi();
  late final TabController _tabs = TabController(
      length: 2, vsync: this, initialIndex: widget.showMap ? 1 : 0);
  DisasterData? _data;
  String? _error;
  bool _loading = true;
  LatLng? _focus;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _api.dispose();
    _tabs.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final res = await _api.load();
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

  void _showOnMap(DisasterAlert a) {
    setState(() => _focus = a.point);
    _tabs.animateTo(1);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: AppBar(
        backgroundColor: AppColors.appBar,
        foregroundColor: Colors.white,
        elevation: 0,
        title: Text(tr.disasterTitle,
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
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
        bottom: TabBar(
          controller: _tabs,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white60,
          indicatorColor: Colors.white,
          tabs: [
            Tab(
                icon: const Icon(Icons.warning_amber_rounded),
                text: tr.alertsTab),
            Tab(icon: const Icon(Icons.map_outlined), text: tr.mapTab),
          ],
        ),
      ),
      body: _loading
          ? Center(child: CircularProgressIndicator(color: AppColors.primary))
          : _data == null
              ? Padding(
                  padding: const EdgeInsets.all(16),
                  child: _box(
                      _error ?? tr.disasterLoadFailed,
                      AppColors.dangerBg,
                      AppColors.dangerBorder,
                      AppColors.dangerText,
                      Icons.error_outline),
                )
              : TabBarView(
                  controller: _tabs,
                  physics: const NeverScrollableScrollPhysics(), // map pans
                  children: [
                    _alertsTab(_data!),
                    Column(children: [
                      if (_data!.home == null)
                        Padding(
                          padding: const EdgeInsets.fromLTRB(12, 10, 12, 0),
                          child: _box(
                              tr.noHomePin,
                              AppColors.infoBg,
                              AppColors.infoBorder,
                              AppColors.infoText,
                              Icons.info_outline),
                        ),
                      Expanded(child: HazardMap(data: _data!, focus: _focus)),
                    ]),
                  ],
                ),
    );
  }

  Widget _alertsTab(DisasterData d) {
    final active = d.active;
    final past = d.alerts.where((a) => !a.isActive).toList();
    return RefreshIndicator(
      color: AppColors.primary,
      onRefresh: _load,
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 620),
          child: ListView(
            physics: const AlwaysScrollableScrollPhysics(),
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
            children: [
              if (active.isEmpty)
                _box(
                    tr.noActiveAlerts,
                    AppColors.successBg,
                    AppColors.successBorder,
                    AppColors.successText,
                    Icons.verified_user_outlined)
              else
                for (final a in active) ...[
                  _alertCard(a),
                  const SizedBox(height: 12),
                ],
              const SizedBox(height: 8),
              _box(
                  tr.emergencyHotlinesHint,
                  AppColors.infoBg,
                  AppColors.infoBorder,
                  AppColors.infoText,
                  Icons.call_outlined),
              if (past.isNotEmpty) ...[
                const SizedBox(height: 20),
                ComplaintSectionLabel(tr.pastAlerts),
                for (final a in past) ...[
                  _alertCard(a),
                  const SizedBox(height: 10),
                ],
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _alertCard(DisasterAlert a) {
    final color =
        a.isActive ? DisasterStyle.severity(a.severity) : AppColors.slate400;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: a.isActive ? color.withValues(alpha: .08) : AppColors.surface,
        border: Border.all(
            color: a.isActive ? color.withValues(alpha: .5) : AppColors.border,
            width: a.isActive ? 1.5 : 1),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            Container(
              width: 42,
              height: 42,
              decoration: BoxDecoration(
                  color: color.withValues(alpha: .15),
                  borderRadius: BorderRadius.circular(12)),
              child: Icon(DisasterStyle.icon(a.type), color: color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Wrap(spacing: 6, runSpacing: 4, children: [
                    ComplaintPill(
                        a.isActive ? tr.activeAlert : tr.alertEnded, color),
                    if (a.severity != null)
                      ComplaintPill(tr.severityLabel(a.severity!), color),
                  ]),
                  const SizedBox(height: 4),
                  Text(a.title,
                      style: TextStyle(
                          fontSize: 15.5,
                          fontWeight: FontWeight.w900,
                          color: AppColors.slate800)),
                ],
              ),
            ),
          ]),
          if (a.message != null) ...[
            const SizedBox(height: 10),
            Text(a.message!,
                style: TextStyle(
                    fontSize: 13.5, height: 1.45, color: AppColors.slate800)),
          ],
          const SizedBox(height: 10),
          if (a.location != null)
            _info(Icons.place_outlined, '${tr.affectedArea}: ${a.location}'),
          if (a.evacuation != null)
            _info(Icons.health_and_safety_outlined,
                '${tr.evacuationCenter}: ${a.evacuation}'),
          _info(Icons.schedule, ComplaintStyle.dateTime(a.createdAt)),
          if (a.isActive && a.point != null) ...[
            const SizedBox(height: 8),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: () => _showOnMap(a),
                icon: const Icon(Icons.map_outlined, size: 18),
                label: Text(tr.viewOnMap),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _info(IconData icon, String text) => Padding(
        padding: const EdgeInsets.only(top: 4),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 16, color: AppColors.slate500),
          const SizedBox(width: 6),
          Expanded(
            child: Text(text,
                style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: AppColors.slate500)),
          ),
        ]),
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
