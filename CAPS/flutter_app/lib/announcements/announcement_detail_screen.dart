import 'package:flutter/material.dart';

import '../l10n/app_text.dart';
import '../theme/app_theme.dart';
import 'announcement_api.dart';
import 'announcement_widgets.dart';

/// Full announcement: photos (swipe; tap to zoom), category, posted date,
/// schedule, the full text and any other attached files.
class AnnouncementDetailScreen extends StatefulWidget {
  const AnnouncementDetailScreen({super.key, required this.announcement});

  final Announcement announcement;

  @override
  State<AnnouncementDetailScreen> createState() =>
      _AnnouncementDetailScreenState();
}

class _AnnouncementDetailScreenState extends State<AnnouncementDetailScreen> {
  int _page = 0;

  Announcement get a => widget.announcement;

  @override
  Widget build(BuildContext context) {
    final color = AnnouncementStyle.category(a.category);
    final schedule = AnnouncementStyle.schedule(a);
    final images = a.images;
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: AppBar(
        backgroundColor: AppColors.appBar,
        foregroundColor: Colors.white,
        elevation: 0,
        title: Text(tr.moduleLabel('announcements'),
            style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800)),
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 640),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
            children: [
              if (images.isNotEmpty) ...[
                ClipRRect(
                  borderRadius: BorderRadius.circular(20),
                  child: SizedBox(
                    height: 230,
                    child: PageView.builder(
                      itemCount: images.length,
                      onPageChanged: (i) => setState(() => _page = i),
                      itemBuilder: (_, i) => GestureDetector(
                        onTap: () => _zoom(images[i]),
                        child: AnnouncementImage(images[i], height: 230),
                      ),
                    ),
                  ),
                ),
                if (images.length > 1)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        for (var i = 0; i < images.length; i++)
                          Container(
                            margin: const EdgeInsets.symmetric(horizontal: 3),
                            width: i == _page ? 18 : 7,
                            height: 7,
                            decoration: BoxDecoration(
                              color: i == _page
                                  ? AppColors.primary
                                  : AppColors.slate200,
                              borderRadius: BorderRadius.circular(50),
                            ),
                          ),
                      ],
                    ),
                  ),
                const SizedBox(height: 16),
              ],
              Container(
                padding: const EdgeInsets.all(18),
                decoration: BoxDecoration(
                  color: AppColors.surface,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: AppColors.border),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(spacing: 6, runSpacing: 6, children: [
                      AnnouncementPill(
                          tr.announcementCategory(a.category), color,
                          icon: AnnouncementStyle.icon(a.category)),
                      if (a.isEnded)
                        AnnouncementPill(tr.endedLabel, AppColors.slate400),
                    ]),
                    const SizedBox(height: 12),
                    SelectableText(a.title,
                        style: TextStyle(
                            fontSize: 20,
                            height: 1.25,
                            fontWeight: FontWeight.w900,
                            color: AppColors.slate800)),
                    const SizedBox(height: 10),
                    _info(Icons.schedule,
                        tr.postedOn(AnnouncementStyle.date(a.datePosted))),
                    if (schedule.isNotEmpty)
                      _info(Icons.event_outlined, schedule),
                    Divider(height: 28, color: AppColors.border),
                    SelectableText(a.details,
                        style: TextStyle(
                            fontSize: 14,
                            height: 1.6,
                            color: AppColors.slate800)),
                  ],
                ),
              ),
              if (a.files.isNotEmpty) ...[
                const SizedBox(height: 16),
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: AppColors.surface,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: AppColors.border),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(tr.attachment.toUpperCase(),
                          style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.w900,
                              letterSpacing: .8,
                              color: AppColors.heading2)),
                      const SizedBox(height: 8),
                      for (final f in a.files)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 6),
                          child: Row(children: [
                            Icon(
                                f.ext == 'pdf'
                                    ? Icons.picture_as_pdf_outlined
                                    : Icons.insert_drive_file_outlined,
                                color: AppColors.primary),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(f.name,
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style: TextStyle(
                                      fontSize: 13,
                                      fontWeight: FontWeight.w700,
                                      color: AppColors.slate800)),
                            ),
                            if (f.size > 0)
                              Text(_size(f.size),
                                  style: TextStyle(
                                      fontSize: 11, color: AppColors.slate400)),
                          ]),
                        ),
                      const SizedBox(height: 4),
                      Text(tr.attachmentAtBarangay,
                          style: TextStyle(
                              fontSize: 11, color: AppColors.slate400)),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _info(IconData icon, String text) => Padding(
        padding: const EdgeInsets.only(top: 4),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 15, color: AppColors.slate400),
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

  void _zoom(AnnouncementFile f) {
    showDialog(
      context: context,
      builder: (_) => Dialog(
        insetPadding: const EdgeInsets.all(12),
        child:
            InteractiveViewer(child: AnnouncementImage(f, fit: BoxFit.contain)),
      ),
    );
  }

  String _size(int b) {
    if (b >= 1024 * 1024) return '${(b / 1024 / 1024).toStringAsFixed(1)} MB';
    if (b >= 1024) return '${(b / 1024).toStringAsFixed(0)} KB';
    return '$b B';
  }
}
