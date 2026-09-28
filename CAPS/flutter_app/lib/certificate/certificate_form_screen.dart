import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import '../complaint/complaint_widgets.dart';
import '../l10n/app_text.dart';
import '../models/resident.dart';
import '../theme/app_theme.dart';
import 'certificate_api.dart';

/// Request a document: choose the type → requirements (check + optional
/// photo) → additional information set up by the admin → purpose → send.
/// Pops the saved [CertRequest] on success.
class CertificateFormScreen extends StatefulWidget {
  const CertificateFormScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<CertificateFormScreen> createState() => _CertificateFormScreenState();
}

class _CertificateFormScreenState extends State<CertificateFormScreen> {
  final _api = CertificateApi();
  final _picker = ImagePicker();
  final _formKey = GlobalKey<FormState>();
  final _purpose = TextEditingController();

  List<CertDocType>? _types;
  CertNotices _notices = const CertNotices();
  String? _error;
  bool _loading = true;
  bool _sending = false;

  CertDocType? _type;
  final Set<String> _checked = {};
  final Map<String, CertAttachment> _photos = {};
  final Map<String, TextEditingController> _extra = {};
  final Map<String, String> _selects = {};

  static const _maxBytes = 8 * 1024 * 1024;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _api.dispose();
    _purpose.dispose();
    for (final c in _extra.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    final id = widget.resident.residentId;
    final results = await Future.wait([_api.types(id), _api.notices(id)]);
    if (!mounted) return;
    final types = results[0];
    final notices = results[1];
    setState(() {
      _loading = false;
      if (types.ok) {
        _types = types.data as List<CertDocType>;
        _error = null;
      } else {
        _error = types.message;
      }
      if (notices.ok) _notices = notices.data as CertNotices;
    });
  }

  void _choose(CertDocType t) {
    for (final c in _extra.values) {
      c.dispose();
    }
    setState(() {
      _type = t;
      _checked.clear();
      _photos.clear();
      _selects.clear();
      _extra
        ..clear()
        ..addEntries(t.extraFields
            .where((f) => f.type != 'select')
            .map((f) => MapEntry(f.key, TextEditingController())));
    });
  }

  Future<void> _attach(String requirement) async {
    final x = await _picker.pickImage(
        source: ImageSource.gallery,
        imageQuality: 80,
        maxWidth: 1600,
        maxHeight: 1600);
    if (x == null) return;
    final bytes = await x.readAsBytes();
    if (!mounted) return;
    if (bytes.length > _maxBytes) {
      _snack(tr.photoTooLarge, error: true);
      return;
    }
    setState(() {
      _photos[requirement] = CertAttachment(requirement, bytes, x.name);
      _checked.add(requirement);
    });
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    final t = _type!;
    final extra = <String, String>{
      for (final e in _extra.entries) e.key: e.value.text.trim(),
      ..._selects,
    }..removeWhere((_, v) => v.isEmpty);
    setState(() => _sending = true);
    final res = await _api.submit(
      widget.resident.residentId,
      docType: t.name,
      purpose: _purpose.text.trim(),
      extra: extra,
      requirements: _checked.toList(),
      attachments: _photos.values.toList(),
    );
    if (!mounted) return;
    setState(() => _sending = false);
    if (res.ok) {
      Navigator.of(context).pop(res.data);
    } else {
      _snack(res.message, error: true);
    }
  }

  void _snack(String m, {bool error = false}) =>
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(m),
        backgroundColor: error ? AppColors.danger : null,
      ));

  @override
  Widget build(BuildContext context) {
    final t = _type;
    return PopScope(
      canPop: t == null,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop && t != null) setState(() => _type = null);
      },
      child: Scaffold(
        backgroundColor: AppColors.scaffold,
        appBar: complaintAppBar(t?.name ?? tr.requestDocument,
            t == null ? tr.chooseDocument : tr.requiredFieldsNote,
            icon: Icons.description_outlined),
        body: _loading
            ? Center(child: CircularProgressIndicator(color: AppColors.primary))
            : Align(
                alignment: Alignment.topCenter,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 620),
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 40),
                    child: t == null ? _typeList() : _form(t),
                  ),
                ),
              ),
      ),
    );
  }

  // ── Step 1: choose the document ─────────────────────────────────────────
  Widget _typeList() {
    final types = _types;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ..._warnings(),
        if (types == null)
          _box(_error ?? tr.certLoadFailed, AppColors.dangerBg,
              AppColors.dangerBorder, AppColors.dangerText, Icons.error_outline)
        else if (types.isEmpty)
          _box(tr.noDocTypes, AppColors.infoBg, AppColors.infoBorder,
              AppColors.infoText, Icons.info_outline)
        else ...[
          ComplaintSectionLabel(tr.chooseDocument),
          for (final d in types) ...[
            _typeCard(d),
            const SizedBox(height: 10),
          ],
        ],
      ],
    );
  }

  List<Widget> _warnings() => [
        if (_notices.activeBlotterCases > 0) ...[
          _box(tr.blotterWarning(_notices.activeBlotterCases), AppColors.warnBg,
              AppColors.warnBorder, AppColors.warnText, Icons.gavel_outlined),
          const SizedBox(height: 12),
        ],
        if (_notices.unclaimed > 0) ...[
          _box(
              tr.unclaimedWarning(_notices.unclaimed),
              AppColors.warnBg,
              AppColors.warnBorder,
              AppColors.warnText,
              Icons.inventory_2_outlined),
          const SizedBox(height: 12),
        ],
      ];

  Widget _typeCard(CertDocType d) {
    final color = CertStyle.named(d.color);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: () => _choose(d),
        child: ComplaintCard(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(
              width: 46,
              height: 46,
              decoration: BoxDecoration(
                  color: color.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(14)),
              child: Icon(CertStyle.namedIcon(d.icon), color: color),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(d.name,
                      style: TextStyle(
                          fontSize: 14.5,
                          fontWeight: FontWeight.w900,
                          color: AppColors.slate800)),
                  if (d.description != null) ...[
                    const SizedBox(height: 2),
                    Text(d.description!,
                        style:
                            TextStyle(fontSize: 12, color: AppColors.slate500)),
                  ],
                  if (d.requirements.isNotEmpty) ...[
                    const SizedBox(height: 4),
                    Text(
                        '${tr.requirementsLabel}: ${d.requirements.join(', ')}',
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                            fontSize: 11.5, color: AppColors.slate400)),
                  ],
                ],
              ),
            ),
            Icon(Icons.chevron_right, color: AppColors.slate400),
          ]),
        ),
      ),
    );
  }

  // ── Step 2: the form ────────────────────────────────────────────────────
  Widget _form(CertDocType t) {
    return Form(
      key: _formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ..._warnings(),
          if (t.requirements.isNotEmpty) ...[
            ComplaintSectionLabel(tr.requirementsLabel),
            ComplaintCard(
              padding: const EdgeInsets.fromLTRB(6, 10, 12, 10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(10, 0, 0, 6),
                    child: Text(tr.requirementsHint,
                        style: TextStyle(
                            fontSize: 12,
                            height: 1.4,
                            color: AppColors.slate500)),
                  ),
                  for (final r in t.requirements) _requirement(r),
                ],
              ),
            ),
            const SizedBox(height: 18),
          ],
          if (t.extraFields.isNotEmpty) ...[
            ComplaintSectionLabel(tr.additionalInfo),
            ComplaintCard(
              child: Column(children: [
                for (var i = 0; i < t.extraFields.length; i++) ...[
                  if (i > 0) const SizedBox(height: 14),
                  _extraField(t.extraFields[i]),
                ],
              ]),
            ),
            const SizedBox(height: 18),
          ],
          ComplaintSectionLabel('${tr.purposeLabel} *'),
          ComplaintCard(
            child: TextFormField(
              controller: _purpose,
              minLines: 2,
              maxLines: 4,
              maxLength: 500,
              decoration: _decoration(tr.purposeHint),
              validator: (v) =>
                  (v ?? '').trim().isEmpty ? tr.purposeRequired : null,
            ),
          ),
          const SizedBox(height: 22),
          SizedBox(
            height: 50,
            child: FilledButton.icon(
              onPressed: _sending ? null : _submit,
              style: FilledButton.styleFrom(
                  backgroundColor: AppColors.primary,
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14))),
              icon: _sending
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.send_rounded),
              label: Text(tr.submitRequest,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
          ),
        ],
      ),
    );
  }

  Widget _requirement(String r) {
    final photo = _photos[r];
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Checkbox(
          value: _checked.contains(r),
          activeColor: AppColors.primary,
          onChanged: (v) => setState(() {
            if (v == true) {
              _checked.add(r);
            } else {
              _checked.remove(r);
              _photos.remove(r);
            }
          }),
        ),
        Expanded(
          child: Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(r,
                    style: TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        color: AppColors.slate800)),
                if (photo != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Row(children: [
                      ClipRRect(
                        borderRadius: BorderRadius.circular(8),
                        child: Image.memory(photo.bytes,
                            width: 44, height: 44, fit: BoxFit.cover),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(tr.photoAttached,
                            style: TextStyle(
                                fontSize: 12,
                                color: AppColors.successText,
                                fontWeight: FontWeight.w700)),
                      ),
                      IconButton(
                        tooltip: tr.remove,
                        onPressed: () => setState(() => _photos.remove(r)),
                        icon: Icon(Icons.close,
                            size: 18, color: AppColors.slate400),
                      ),
                    ]),
                  ),
              ],
            ),
          ),
        ),
        if (photo == null)
          IconButton(
            tooltip: tr.attachPhoto,
            onPressed: () => _attach(r),
            icon: Icon(Icons.add_a_photo_outlined, color: AppColors.primary),
          ),
      ],
    );
  }

  Widget _extraField(CertExtraField f) {
    final label = f.required ? '${f.label} *' : f.label;
    String? req(String? v) => f.required && (v ?? '').trim().isEmpty
        ? tr.fieldRequired(f.label)
        : null;

    if (f.type == 'select') {
      return DropdownButtonFormField<String>(
        initialValue: _selects[f.key],
        isExpanded: true,
        decoration: _decoration(tr.selectOption, label: label),
        items: [
          for (final o in f.options)
            DropdownMenuItem(
                value: o, child: Text(o, overflow: TextOverflow.ellipsis)),
        ],
        onChanged: (v) => setState(() {
          if (v == null) {
            _selects.remove(f.key);
          } else {
            _selects[f.key] = v;
          }
        }),
        validator: req,
      );
    }
    final c = _extra[f.key]!;
    if (f.type == 'date') {
      return TextFormField(
        controller: c,
        readOnly: true,
        decoration: _decoration('YYYY-MM-DD', label: label).copyWith(
            suffixIcon: const Icon(Icons.calendar_today_outlined, size: 18)),
        validator: req,
        onTap: () async {
          final now = DateTime.now();
          final d = await showDatePicker(
              context: context,
              initialDate: DateTime.tryParse(c.text) ?? now,
              firstDate: DateTime(1900),
              lastDate: DateTime(now.year + 5));
          if (d != null) {
            c.text = '${d.year}-${d.month.toString().padLeft(2, '0')}-'
                '${d.day.toString().padLeft(2, '0')}';
          }
        },
      );
    }
    return TextFormField(
      controller: c,
      minLines: f.type == 'textarea' ? 3 : 1,
      maxLines: f.type == 'textarea' ? 5 : 1,
      keyboardType:
          f.type == 'number' ? TextInputType.number : TextInputType.text,
      inputFormatters: f.type == 'number'
          ? [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))]
          : null,
      decoration: _decoration('', label: label),
      validator: req,
    );
  }

  InputDecoration _decoration(String hint, {String? label}) => InputDecoration(
        labelText: label,
        hintText: hint.isEmpty ? null : hint,
        filled: true,
        fillColor: AppColors.fieldFill,
        border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide.none),
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
                      fontSize: 12.5,
                      height: 1.4,
                      fontWeight: FontWeight.w600,
                      color: fg)),
            ),
          ],
        ),
      );
}
