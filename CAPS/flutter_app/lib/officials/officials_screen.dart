import 'package:flutter/material.dart';

import '../config/api_config.dart';
import '../l10n/app_text.dart';
import '../theme/app_theme.dart';
import 'officials_api.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// OFFICIALS — the current barangay officials (read-only).
/// Same list and order as the admin Officials page: Punong Barangay first,
/// then Secretary/Treasurer, Kagawads (with committee) and SK Chairperson.
/// Tap an official for their position and term. Talks to
/// `user/backend/officials.php`. Kept in its own `officials/` folder.
/// ─────────────────────────────────────────────────────────────────────────
class OfficialsScreen extends StatefulWidget {
  const OfficialsScreen({super.key});

  @override
  State<OfficialsScreen> createState() => _OfficialsScreenState();
}

class _OfficialsScreenState extends State<OfficialsScreen> {
  final _api = OfficialsApi();
  List<Official> _officials = const [];
  bool _loading = true;
  String? _error;

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
      _error = res.ok ? null : res.message;
      if (res.ok) _officials = res.data ?? const [];
    });
  }

  @override
  Widget build(BuildContext context) {
    final captain = _officials.where((o) => o.group == 'captain').toList();
    final executive = _officials.where((o) => o.group == 'executive').toList();
    final kagawads = _officials.where((o) => o.group == 'kagawad').toList();
    final others =
        _officials.where((o) => o.group == 'sk' || o.group == 'other').toList();

    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: AppBar(
        backgroundColor: AppColors.appBar,
        foregroundColor: Colors.white,
        elevation: 0,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(tr.moduleLabel('officials'),
                style:
                    const TextStyle(fontSize: 15, fontWeight: FontWeight.w800)),
            Text(tr.officialsSubtitle,
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
                      if (_error != null)
                        _message(Icons.error_outline, _error!),
                      if (_error == null && _officials.isEmpty)
                        _message(Icons.groups_outlined, tr.noOfficials),
                      for (final c in captain) _captainCard(c),
                      if (executive.isNotEmpty) ...[
                        _label(tr.executiveOfficers),
                        _grid(executive),
                      ],
                      if (kagawads.isNotEmpty) ...[
                        _label(tr.kagawads),
                        _grid(kagawads),
                      ],
                      if (others.isNotEmpty) ...[
                        _label(tr.otherOfficials),
                        _grid(others),
                      ],
                    ],
                  ),
                ),
              ),
            ),
    );
  }

  // ── Punong Barangay ───────────────────────────────────────────────────
  Widget _captainCard(Official o) {
    return GestureDetector(
      onTap: () => _details(o),
      child: Container(
        margin: const EdgeInsets.only(bottom: 20),
        padding: const EdgeInsets.all(22),
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
          children: [
            _photo(o, 112, onGradient: true),
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: .18),
                borderRadius: BorderRadius.circular(50),
              ),
              child: Text(tr.punongBarangay.toUpperCase(),
                  style: const TextStyle(
                      color: Colors.white,
                      fontSize: 10,
                      fontWeight: FontWeight.w900,
                      letterSpacing: 1.2)),
            ),
            const SizedBox(height: 8),
            Text(o.name.isEmpty ? '—' : o.name,
                textAlign: TextAlign.center,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 20,
                    fontWeight: FontWeight.w900,
                    height: 1.15)),
            if (o.termEnd != null) ...[
              const SizedBox(height: 6),
              Text(_term(o),
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      color: Colors.white.withValues(alpha: .75),
                      fontSize: 12,
                      fontWeight: FontWeight.w600)),
            ],
          ],
        ),
      ),
    );
  }

  // ── Grid of officials ─────────────────────────────────────────────────
  Widget _grid(List<Official> list) {
    return LayoutBuilder(builder: (context, c) {
      final cols = c.maxWidth >= 520 ? 3 : 2;
      final w = (c.maxWidth - 12 * (cols - 1)) / cols;
      return Padding(
        padding: const EdgeInsets.only(bottom: 20),
        child: Wrap(
          alignment: WrapAlignment.center,
          spacing: 12,
          runSpacing: 12,
          children: [for (final o in list) SizedBox(width: w, child: _tile(o))],
        ),
      );
    });
  }

  Widget _tile(Official o) {
    return Material(
      color: AppColors.surface,
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: () => _details(o),
        child: Container(
          padding: const EdgeInsets.fromLTRB(12, 18, 12, 16),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: AppColors.border),
          ),
          child: Column(
            children: [
              _photo(o, 72),
              const SizedBox(height: 10),
              Text(o.name.isEmpty ? '—' : o.name,
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      fontSize: 13,
                      height: 1.2,
                      fontWeight: FontWeight.w800,
                      color: AppColors.slate800)),
              const SizedBox(height: 4),
              Text(_title(o),
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: AppColors.primary)),
              if (o.committee != null) ...[
                const SizedBox(height: 2),
                Text(tr.committeeOn(o.committee!),
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style:
                        TextStyle(fontSize: 10.5, color: AppColors.slate500)),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// "Kagawad - Education" → "Kagawad"; other titles unchanged.
  String _title(Official o) {
    final base = o.committee == null
        ? o.position
        : o.position.substring(0, o.position.indexOf(' - '));
    return tr.officialPosition(base);
  }

  Widget _photo(Official o, double size, {bool onGradient = false}) {
    final initials = Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: onGradient
            ? Colors.white.withValues(alpha: .2)
            : AppColors.primary.withValues(alpha: .12),
      ),
      child: Text(o.initials,
          style: TextStyle(
              fontSize: size * .34,
              fontWeight: FontWeight.w900,
              color: onGradient ? Colors.white : AppColors.primary)),
    );
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(
            color: onGradient
                ? Colors.white.withValues(alpha: .6)
                : AppColors.border,
            width: onGradient ? 3 : 2),
      ),
      child: ClipOval(
        child: o.photoUrl == null
            ? initials
            : Image.network('${ApiConfig.baseUrl}/${o.photoUrl}',
                fit: BoxFit.cover,
                width: size,
                height: size,
                webHtmlElementStrategy: WebHtmlElementStrategy.fallback,
                errorBuilder: (_, __, ___) => initials),
      ),
    );
  }

  void _details(Official o) {
    showModalBottomSheet(
      context: context,
      showDragHandle: true,
      builder: (_) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _photo(o, 96),
              const SizedBox(height: 12),
              Text(o.name.isEmpty ? '—' : o.name,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w900,
                      color: AppColors.slate800)),
              const SizedBox(height: 4),
              Text(_title(o),
                  style: TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w800,
                      color: AppColors.primary)),
              if (o.committee != null)
                Text(tr.committeeOn(o.committee!),
                    style: TextStyle(fontSize: 12, color: AppColors.slate500)),
              const SizedBox(height: 16),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: AppColors.surfaceAlt,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: AppColors.border),
                ),
                child: Row(children: [
                  Icon(Icons.event_outlined,
                      size: 18, color: AppColors.slate400),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                        o.termStart == null && o.termEnd == null
                            ? tr.termNotSet
                            : _term(o),
                        style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w700,
                            color: AppColors.slate800)),
                  ),
                ]),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _term(Official o) {
    String d(DateTime? x) =>
        x == null ? '—' : '${tr.monthsShort[x.month - 1]} ${x.day}, ${x.year}';
    return tr.termRange(d(o.termStart), d(o.termEnd));
  }

  Widget _label(String s) => Padding(
        padding: const EdgeInsets.only(left: 4, bottom: 10),
        child: Text(s.toUpperCase(),
            style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w900,
                letterSpacing: .8,
                color: AppColors.heading2)),
      );

  Widget _message(IconData icon, String text) => Container(
        margin: const EdgeInsets.only(bottom: 16),
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
