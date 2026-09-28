import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../l10n/app_text.dart';
import '../theme/app_theme.dart';
import 'disaster_api.dart';

/// OpenStreetMap with the barangay hazard zones (circles), Safe Points,
/// active alerts and the resident's home. Tap a marker for details.
class HazardMap extends StatefulWidget {
  const HazardMap({super.key, required this.data, this.focus});

  final DisasterData data;

  /// Start centred here (e.g. an alert opened from the Alerts tab).
  final LatLng? focus;

  @override
  State<HazardMap> createState() => _HazardMapState();
}

class _HazardMapState extends State<HazardMap> {
  final _map = MapController();

  @override
  void didUpdateWidget(HazardMap old) {
    super.didUpdateWidget(old);
    if (widget.focus != null && widget.focus != old.focus) {
      _map.move(widget.focus!, 17);
    }
  }

  void _details(
      String title, String subtitle, String? body, Color color, IconData icon) {
    showModalBottomSheet(
      context: context,
      showDragHandle: true,
      backgroundColor: AppColors.surface,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Container(
                  width: 42,
                  height: 42,
                  decoration: BoxDecoration(
                      color: color.withValues(alpha: .14),
                      borderRadius: BorderRadius.circular(12)),
                  child: Icon(icon, color: color),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(title,
                          style: TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w900,
                              color: AppColors.slate800)),
                      Text(subtitle,
                          style: TextStyle(
                              fontSize: 12.5,
                              fontWeight: FontWeight.w700,
                              color: color)),
                    ],
                  ),
                ),
              ]),
              if (body != null && body.isNotEmpty) ...[
                const SizedBox(height: 12),
                Text(body,
                    style: TextStyle(
                        fontSize: 13.5,
                        height: 1.45,
                        color: AppColors.slate800)),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Marker _pin(LatLng p, IconData icon, Color color, VoidCallback onTap,
          {double size = 38}) =>
      Marker(
        point: p,
        width: size,
        height: size,
        child: GestureDetector(
          onTap: onTap,
          child: Container(
            decoration: BoxDecoration(
              color: color,
              shape: BoxShape.circle,
              border: Border.all(color: Colors.white, width: 2.5),
              boxShadow: const [
                BoxShadow(
                    color: Colors.black26, blurRadius: 6, offset: Offset(0, 2))
              ],
            ),
            child: Icon(icon, color: Colors.white, size: size * .5),
          ),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final d = widget.data;
    final alertsOnMap = d.active.where((a) => a.point != null).toList();
    return Stack(
      children: [
        FlutterMap(
          mapController: _map,
          options: MapOptions(
            initialCenter: widget.focus ?? d.center,
            initialZoom: 16,
            minZoom: 12,
            maxZoom: 19,
          ),
          children: [
            TileLayer(
              urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
              userAgentPackageName: 'ph.gov.binang2nd.resident',
            ),
            CircleLayer(circles: [
              for (final h in d.hazards)
                CircleMarker(
                  point: h.point,
                  radius: h.radius.toDouble(),
                  useRadiusInMeter: true,
                  color: DisasterStyle.type(h.type)
                      .withValues(alpha: h.isSafePoint ? .18 : .22),
                  borderColor: DisasterStyle.type(h.type),
                  borderStrokeWidth: 1.5,
                ),
              for (final a in alertsOnMap)
                CircleMarker(
                  point: a.point!,
                  radius: a.radius.toDouble(),
                  useRadiusInMeter: true,
                  color:
                      DisasterStyle.severity(a.severity).withValues(alpha: .20),
                  borderColor: DisasterStyle.severity(a.severity),
                  borderStrokeWidth: 3,
                ),
            ]),
            MarkerLayer(markers: [
              for (final h in d.hazards)
                _pin(
                    h.point,
                    DisasterStyle.icon(h.type),
                    DisasterStyle.type(h.type),
                    () => _details(
                        h.title,
                        tr.hazardType(h.type),
                        h.description,
                        DisasterStyle.type(h.type),
                        DisasterStyle.icon(h.type)),
                    size: 32),
              for (final a in alertsOnMap)
                _pin(
                    a.point!,
                    Icons.warning_amber_rounded,
                    DisasterStyle.severity(a.severity),
                    () => _details(
                        a.title,
                        '${tr.activeAlert} · ${tr.severityLabel(a.severity ?? '')}',
                        [
                          a.message,
                          if (a.evacuation != null)
                            '${tr.evacuationCenter}: ${a.evacuation}',
                        ].whereType<String>().join('\n\n'),
                        DisasterStyle.severity(a.severity),
                        Icons.warning_amber_rounded),
                    size: 42),
              if (d.home != null)
                _pin(
                    d.home!,
                    Icons.home_rounded,
                    AppColors.primary,
                    () => _details(tr.yourHome, tr.myHousehold, null,
                        AppColors.primary, Icons.home_rounded),
                    size: 40),
            ]),
            const RichAttributionWidget(attributions: [
              TextSourceAttribution('OpenStreetMap contributors'),
            ]),
          ],
        ),
        // Legend
        Positioned(
          left: 12,
          top: 12,
          child: Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: AppColors.surface.withValues(alpha: .95),
              borderRadius: BorderRadius.circular(12),
              boxShadow: const [
                BoxShadow(color: Colors.black12, blurRadius: 8)
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                for (final t in [
                  'Flood',
                  'Fire',
                  'Structural',
                  'Earthquake',
                  'Safe Point'
                ])
                  if (d.hazards.any((h) => h.type == t))
                    _legend(DisasterStyle.type(t), tr.hazardType(t)),
                if (alertsOnMap.isNotEmpty)
                  _legend(const Color(0xFFE11D48), tr.activeAlert),
                if (d.home != null) _legend(AppColors.primary, tr.yourHome),
              ],
            ),
          ),
        ),
        if (d.home != null)
          Positioned(
            right: 12,
            bottom: 28,
            child: FloatingActionButton.small(
              heroTag: 'home',
              backgroundColor: AppColors.surface,
              foregroundColor: AppColors.primary,
              tooltip: tr.yourHome,
              onPressed: () => _map.move(d.home!, 17),
              child: const Icon(Icons.my_location),
            ),
          ),
      ],
    );
  }

  Widget _legend(Color c, String label) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Container(
              width: 10,
              height: 10,
              decoration: BoxDecoration(color: c, shape: BoxShape.circle)),
          const SizedBox(width: 6),
          Text(label,
              style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppColors.slate800)),
        ]),
      );
}
