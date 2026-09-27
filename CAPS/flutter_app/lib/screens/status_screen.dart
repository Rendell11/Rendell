import 'package:flutter/material.dart';

import '../models/access_request.dart';
import '../l10n/app_text.dart';
import '../services/api_service.dart';
import '../theme/app_theme.dart';
import '../widgets/app_scaffold.dart';
import 'set_password_screen.dart';

/// STEP 2 UI — resident checks the status of their access request by email or
/// contact number. When Approved/Matched, offers to proceed to set a password.
class StatusScreen extends StatefulWidget {
  const StatusScreen({super.key, this.prefillEmail});

  final String? prefillEmail;

  @override
  State<StatusScreen> createState() => _StatusScreenState();
}

class _StatusScreenState extends State<StatusScreen> {
  final _api = ApiService();
  final _identifier = TextEditingController();
  AccessRequest? _request;
  String? _error;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    if (widget.prefillEmail != null) {
      _identifier.text = widget.prefillEmail!;
      _check();
    }
  }

  @override
  void dispose() {
    _identifier.dispose();
    _api.dispose();
    super.dispose();
  }

  Future<void> _check() async {
    final id = _identifier.text.trim();
    if (id.isEmpty) {
      setState(() => _error = tr.enterEmailOrContact);
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });

    final isEmail = id.contains('@');
    final result = await _api.checkStatus(
      email: isEmail ? id : null,
      contactNumber: isEmail ? null : id,
    );

    if (!mounted) return;
    setState(() {
      _loading = false;
      if (result.ok) {
        _request = result.data;
        _error = null;
      } else {
        _request = null;
        _error = result.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return AppScaffold(
      title: tr.requestStatus,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          TextField(
            controller: _identifier,
            decoration: InputDecoration(
              labelText: tr.emailOrContact,
              border: const OutlineInputBorder(),
            ),
            onSubmitted: (_) => _check(),
          ),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: _loading ? null : _check,
            child: _loading
                ? const SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : Text(tr.checkStatus),
          ),
          const SizedBox(height: 20),
          if (_error != null)
            Text(_error!, style: const TextStyle(color: Colors.red)),
          if (_request != null) _statusCard(_request!),
        ],
      ),
    );
  }

  Widget _statusCard(AccessRequest r) {
    final color = _statusColor(r.status);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(r.fullName,
                style: const TextStyle(
                    fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            Text(r.email, style: TextStyle(color: AppColors.slate500)),
            const Divider(height: 24),
            Row(
              children: [
                Icon(Icons.circle, size: 12, color: color),
                const SizedBox(width: 8),
                Text(tr.accessStatusLabel(r.status.db),
                    style: TextStyle(
                        color: color, fontWeight: FontWeight.bold)),
              ],
            ),
            const SizedBox(height: 8),
            Text(r.status.description),
            if ((r.adminReason ?? '').isNotEmpty) ...[
              const SizedBox(height: 8),
              Text('${tr.adminNote}: ${r.adminReason}',
                  style: const TextStyle(fontStyle: FontStyle.italic)),
            ],
            if (r.status.canSetPassword) ...[
              const SizedBox(height: 16),
              FilledButton.icon(
                icon: const Icon(Icons.lock_outline),
                label: Text(tr.setPassword),
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => const SetPasswordScreen(),
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Color _statusColor(AccessStatus s) {
    switch (s) {
      case AccessStatus.approved:
      case AccessStatus.matched:
        return Colors.green;
      case AccessStatus.pending:
      case AccessStatus.forProfiling:
      case AccessStatus.forCorrection:
        return Colors.orange;
      case AccessStatus.disapproved:
      case AccessStatus.rejected:
        return Colors.red;
    }
  }
}
