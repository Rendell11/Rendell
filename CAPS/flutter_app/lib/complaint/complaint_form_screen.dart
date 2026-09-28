import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'complaint_api.dart';
import 'complaint_model.dart';
import 'complaint_widgets.dart';

/// "File a Complaint" form (SOE incidents.php modal + the admin form's fields:
/// category / other, priority, anonymous, location, title, description,
/// optional photo). Pops with the new `CMP-…` code on success.
class ComplaintFormScreen extends StatefulWidget {
  const ComplaintFormScreen({
    super.key,
    required this.resident,
    this.defaultLocation = '',
  });

  final Resident resident;

  /// Pre-filled from the resident's own address; editable.
  final String defaultLocation;

  @override
  State<ComplaintFormScreen> createState() => _ComplaintFormScreenState();
}

class _ComplaintFormScreenState extends State<ComplaintFormScreen> {
  final _api = ComplaintApi();
  final _picker = ImagePicker();
  final _formKey = GlobalKey<FormState>();

  final _title = TextEditingController();
  final _other = TextEditingController();
  final _location = TextEditingController();
  final _description = TextEditingController();

  List<String> _categories = kComplaintCategories;
  List<String> _priorities = kComplaintPriorities;
  String? _category;
  String _priority = 'Medium';
  bool _anonymous = false;
  bool _agreed = false;
  bool _submitting = false;

  Uint8List? _photoBytes;
  String? _photoName;

  static const _maxBytes = 5 * 1024 * 1024;

  @override
  void initState() {
    super.initState();
    _location.text = widget.defaultLocation;
    _api.fetchOptions().then((o) {
      if (!mounted) return;
      setState(() {
        _categories = o.categories;
        _priorities = o.priorities;
        if (!_priorities.contains(_priority)) _priority = _priorities.first;
      });
    });
  }

  @override
  void dispose() {
    _title.dispose();
    _other.dispose();
    _location.dispose();
    _description.dispose();
    _api.dispose();
    super.dispose();
  }

  Future<void> _pickPhoto(ImageSource source) async {
    final x = await _picker.pickImage(source: source, imageQuality: 75);
    if (x == null) return;
    final bytes = await x.readAsBytes();
    if (!mounted) return;
    if (bytes.length > _maxBytes) {
      _snack(tr.photoTooLarge, error: true);
      return;
    }
    setState(() {
      _photoBytes = bytes;
      _photoName = x.name;
    });
  }

  void _choosePhotoSource() {
    showModalBottomSheet(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: Text(tr.takePhoto),
              onTap: () {
                Navigator.pop(ctx);
                _pickPhoto(ImageSource.camera);
              },
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: Text(tr.chooseFromGallery),
              onTap: () {
                Navigator.pop(ctx);
                _pickPhoto(ImageSource.gallery);
              },
            ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
  }

  Future<void> _submit() async {
    if (_submitting) return;
    if (!_formKey.currentState!.validate()) return;
    if (!_agreed) {
      _snack(tr.confirmTruthful, error: true);
      return;
    }
    setState(() => _submitting = true);
    final res = await _api.submit(
      widget.resident.residentId,
      ComplaintDraft(
        category: _category!,
        otherCategory: _category == 'Other' ? _other.text.trim() : '',
        title: _title.text.trim(),
        description: _description.text.trim(),
        location: _location.text.trim(),
        priority: _priority,
        isAnonymous: _anonymous,
      ),
      attachmentBytes: _photoBytes,
      attachmentName: _photoName,
    );
    if (!mounted) return;
    setState(() => _submitting = false);
    if (!res.ok) {
      _snack(res.message, error: true);
      return;
    }
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        icon:
            const Icon(Icons.check_circle, color: AppColors.success, size: 48),
        title: Text(tr.complaintSubmittedTitle),
        content: Text(
          tr.complaintSubmittedBody(res.data ?? '—'),
          textAlign: TextAlign.center,
        ),
        actions: [
          FilledButton(onPressed: () => Navigator.pop(ctx), child: Text(tr.ok)),
        ],
      ),
    );
    if (mounted) Navigator.of(context).pop(res.data ?? '');
  }

  void _snack(String msg, {bool error = false}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(msg),
        backgroundColor: error ? AppColors.danger : null,
      ));

  String? _required(String? v, String msg) =>
      (v == null || v.trim().isEmpty) ? msg : null;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.scaffold,
      appBar: complaintAppBar(tr.fileComplaint, tr.requiredFieldsNote),
      body: SafeArea(
        top: false,
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 560),
            child: Form(
              key: _formKey,
              // Not a ListView: a lazy list disposes off-screen fields, and
              // Form.validate() would then skip them.
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    ComplaintSectionLabel(tr.complaintDetails),
                    ComplaintCard(child: _detailsSection()),
                    const SizedBox(height: 16),
                    ComplaintSectionLabel(tr.placeAndDescription),
                    ComplaintCard(child: _descriptionSection()),
                    const SizedBox(height: 16),
                    ComplaintSectionLabel(tr.attachmentOptional),
                    ComplaintCard(child: _attachmentSection()),
                    const SizedBox(height: 16),
                    ComplaintCard(child: _privacySection()),
                    const SizedBox(height: 20),
                    _submitButton(),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _label(String text, {bool required = true}) => Padding(
        padding: const EdgeInsets.only(left: 2, bottom: 6),
        child: Text.rich(TextSpan(
          text: text.toUpperCase(),
          style: TextStyle(
              fontSize: 10,
              fontWeight: FontWeight.w900,
              letterSpacing: .8,
              color: AppColors.slate400),
          children: [
            if (required)
              const TextSpan(
                  text: ' *', style: TextStyle(color: AppColors.danger)),
          ],
        )),
      );

  Widget _detailsSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _label(tr.complaintTitle),
        TextFormField(
          controller: _title,
          maxLength: 255,
          textCapitalization: TextCapitalization.sentences,
          decoration: AppTheme.field(tr.complaintTitleHint, icon: Icons.title)
              .copyWith(counterText: ''),
          validator: (v) => _required(v, tr.enterTitle),
        ),
        const SizedBox(height: 14),
        _label(tr.category),
        DropdownButtonFormField<String>(
          value: _category,
          isExpanded: true,
          decoration:
              AppTheme.field(tr.chooseCategoryHint, icon: Icons.label_outline),
          items: [
            for (final c in _categories)
              DropdownMenuItem(
                  value: c, child: Text(tr.complaintCategoryLabel(c))),
          ],
          onChanged: (v) => setState(() => _category = v),
          validator: (v) => v == null ? tr.chooseCategory : null,
        ),
        if (_category == 'Other') ...[
          const SizedBox(height: 14),
          _label(tr.complaintKind),
          TextFormField(
            controller: _other,
            maxLength: 255,
            decoration:
                AppTheme.field(tr.complaintKindHint, icon: Icons.edit_outlined)
                    .copyWith(counterText: ''),
            validator: (v) => _category == 'Other'
                ? _required(v, tr.enterComplaintKind)
                : null,
          ),
        ],
        const SizedBox(height: 14),
        _label(tr.priority),
        Row(
          children: [
            for (final p in _priorities)
              Expanded(
                child: Padding(
                  padding:
                      EdgeInsets.only(right: p == _priorities.last ? 0 : 8),
                  child: _priorityOption(p),
                ),
              ),
          ],
        ),
      ],
    );
  }

  Widget _priorityOption(String p) {
    final selected = _priority == p;
    final color = ComplaintStyle.priority(p);
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => setState(() => _priority = p),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
        decoration: BoxDecoration(
          color: selected ? color.withValues(alpha: .12) : AppColors.surface,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
              color: selected ? color : AppColors.slate200,
              width: selected ? 1.6 : 1.2),
        ),
        child: Text(
          tr.priorityLabel(p).replaceFirst(' (', '\n('),
          textAlign: TextAlign.center,
          style: TextStyle(
              fontSize: 12,
              height: 1.15,
              fontWeight: FontWeight.w800,
              color: selected ? color : AppColors.slate500),
        ),
      ),
    );
  }

  Widget _descriptionSection() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _label(tr.incidentPlace),
        TextFormField(
          controller: _location,
          maxLength: 255,
          decoration: AppTheme.field(tr.incidentPlaceHint,
                  icon: Icons.location_on_outlined)
              .copyWith(counterText: ''),
          validator: (v) => _required(v, tr.enterIncidentPlace),
        ),
        const SizedBox(height: 14),
        _label(tr.descriptionLabel),
        TextFormField(
          controller: _description,
          minLines: 5,
          maxLines: 8,
          maxLength: 2000,
          textCapitalization: TextCapitalization.sentences,
          decoration: AppTheme.field(tr.descriptionHint),
          validator: (v) => _required(v, tr.enterDescription),
        ),
      ],
    );
  }

  Widget _attachmentSection() {
    if (_photoBytes == null) {
      return InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: _choosePhotoSource,
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 22),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: AppColors.slate200, width: 1.4),
            color: AppColors.surfaceAlt,
          ),
          child: Column(
            children: [
              Icon(Icons.add_a_photo_outlined,
                  size: 30, color: AppColors.primary),
              const SizedBox(height: 8),
              Text(tr.addPhotoEvidence,
                  style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AppColors.slate800)),
              const SizedBox(height: 2),
              Text(tr.photoLimits,
                  style: TextStyle(fontSize: 11, color: AppColors.slate400)),
            ],
          ),
        ),
      );
    }
    return Column(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(14),
          child: Image.memory(_photoBytes!,
              height: 180, width: double.infinity, fit: BoxFit.cover),
        ),
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: Text(_photoName ?? 'attachment',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(fontSize: 12, color: AppColors.slate500)),
            ),
            TextButton.icon(
              onPressed: _choosePhotoSource,
              icon: const Icon(Icons.swap_horiz, size: 16),
              label: Text(tr.replace),
            ),
            TextButton.icon(
              onPressed: () => setState(() {
                _photoBytes = null;
                _photoName = null;
              }),
              style: TextButton.styleFrom(foregroundColor: AppColors.danger),
              icon: const Icon(Icons.delete_outline, size: 16),
              label: Text(tr.remove),
            ),
          ],
        ),
      ],
    );
  }

  Widget _privacySection() {
    // Own Material so the list tiles' ink shows on top of the white card.
    return Material(
      type: MaterialType.transparency,
      child: Column(
        children: [
          SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            value: _anonymous,
            activeColor: AppColors.primary,
            onChanged: (v) => setState(() => _anonymous = v),
            title: Text(tr.submitAnonymously,
                style:
                    const TextStyle(fontSize: 14, fontWeight: FontWeight.w800)),
            subtitle: Text(tr.submitAnonymouslySub,
                style: TextStyle(fontSize: 11.5, color: AppColors.slate500)),
          ),
          const Divider(height: 20),
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: _agreed,
            activeColor: AppColors.primary,
            onChanged: (v) => setState(() => _agreed = v ?? false),
            title: Text(tr.truthfulnessCheck,
                style: TextStyle(fontSize: 12, color: AppColors.slate800)),
          ),
        ],
      ),
    );
  }

  Widget _submitButton() {
    return DecoratedBox(
      decoration: AppTheme.primaryButton,
      child: SizedBox(
        height: 52,
        child: TextButton(
          onPressed: _submitting ? null : _submit,
          style: TextButton.styleFrom(
            foregroundColor: Colors.white,
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          ),
          child: _submitting
              ? const SizedBox(
                  width: 22,
                  height: 22,
                  child: CircularProgressIndicator(
                      strokeWidth: 2.4, color: Colors.white))
              : Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.send_rounded, size: 18),
                    const SizedBox(width: 8),
                    Flexible(
                      child: Text(tr.submitComplaint,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              fontSize: 15, fontWeight: FontWeight.w800)),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}
