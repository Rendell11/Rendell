import 'dart:async';

import 'package:flutter/material.dart';

import '../models/chat_message.dart';
import '../models/resident.dart';
import '../services/api_service.dart';
import '../theme/app_theme.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// CHAT — resident ⇄ barangay staff (ported from SOE emergency_chat.php)
/// Single thread per resident. Resident bubbles right (accent), staff left.
/// Polls chat.php every 3s. Includes an emergency hotlines sheet.
/// ─────────────────────────────────────────────────────────────────────────
class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key, required this.resident});

  final Resident resident;

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final _api = ApiService();
  final _input = TextEditingController();
  final _scroll = ScrollController();

  ChatThread _thread = const ChatThread();
  bool _loading = true;
  bool _sending = false;
  Timer? _poll;

  int get _rid => widget.resident.residentId;

  @override
  void initState() {
    super.initState();
    _load(initial: true);
    _poll = Timer.periodic(const Duration(seconds: 3), (_) => _load());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _input.dispose();
    _scroll.dispose();
    _api.dispose();
    super.dispose();
  }

  Future<void> _load({bool initial = false}) async {
    final res = await _api.chatList(_rid);
    if (!mounted) return;
    if (res.ok && res.data != null) {
      final wasAtBottom = _isNearBottom();
      final oldCount = _thread.messages.length;
      setState(() {
        _thread = res.data!;
        _loading = false;
      });
      if (initial || (_thread.messages.length > oldCount && wasAtBottom)) {
        _scrollToBottom();
      }
    } else if (initial) {
      setState(() => _loading = false);
    }
  }

  bool _isNearBottom() {
    if (!_scroll.hasClients) return true;
    return _scroll.position.pixels >=
        _scroll.position.maxScrollExtent - 160;
  }

  void _scrollToBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(_scroll.position.maxScrollExtent,
            duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
      }
    });
  }

  Future<void> _send() async {
    final text = _input.text.trim();
    if (text.isEmpty || _sending) return;
    setState(() => _sending = true);
    _input.clear();
    final res = await _api.chatSend(residentId: _rid, message: text);
    if (!mounted) return;
    setState(() => _sending = false);
    if (res.ok) {
      await _load();
      _scrollToBottom();
    } else {
      _input.text = text; // restore on failure
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res.message), backgroundColor: AppColors.danger),
      );
    }
  }

  Future<void> _endConversation() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Isara ang usapan?'),
        content: const Text(
            'Maaari kang magsimula ng bagong mensahe kahit kailan pagkatapos.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Kanselahin')),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Isara')),
        ],
      ),
    );
    if (ok != true) return;
    final res = await _api.chatEnd(_rid);
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text(res.message.isEmpty ? 'Naisara.' : res.message)));
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final status = _thread.status;
    final active = status != 'None' && status != 'Resolved' && status != 'Closed';
    return Scaffold(
      backgroundColor: const Color(0xFFEEF2FB),
      appBar: AppBar(
        backgroundColor: const Color(0xFF0F172A),
        foregroundColor: Colors.white,
        elevation: 0,
        title: Row(
          children: [
            Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                    colors: [AppColors.primary, AppColors.primaryDark]),
                borderRadius: BorderRadius.circular(10),
              ),
              child: const Icon(Icons.support_agent, color: Colors.white, size: 18),
            ),
            const SizedBox(width: 10),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Text('Barangay Chat',
                    style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800)),
                Text(active ? '$status conversation' : 'Barangay Staff',
                    style: const TextStyle(
                        fontSize: 11,
                        color: Colors.white70,
                        fontWeight: FontWeight.w500)),
              ],
            ),
          ],
        ),
        actions: [
          IconButton(
            tooltip: 'Emergency hotlines',
            onPressed: _openHotlines,
            icon: const Icon(Icons.emergency_outlined),
          ),
          if (active)
            IconButton(
              tooltip: 'Isara ang usapan',
              onPressed: _endConversation,
              icon: const Icon(Icons.check_circle_outline),
            ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: _loading
                ? Center(
                    child: CircularProgressIndicator(color: AppColors.primary))
                : _thread.messages.isEmpty
                    ? _emptyState()
                    : _messageList(),
          ),
          _inputBar(),
        ],
      ),
    );
  }

  Widget _emptyState() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 88,
              height: 88,
              decoration: BoxDecoration(
                color: AppColors.primary.withOpacity(.08),
                borderRadius: BorderRadius.circular(28),
              ),
              child: Icon(Icons.forum_outlined,
                  size: 40, color: AppColors.primary),
            ),
            const SizedBox(height: 18),
            Text('Wala pang usapan',
                style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                    color: AppColors.heading)),
            const SizedBox(height: 6),
            Text(
                'Magpadala ng mensahe sa barangay staff. Sasagutin ka nila sa lalong madaling panahon.',
                textAlign: TextAlign.center,
                style: TextStyle(
                    color: AppColors.slate500, fontSize: 13, height: 1.5)),
          ],
        ),
      ),
    );
  }

  Widget _messageList() {
    return ListView.builder(
      controller: _scroll,
      padding: const EdgeInsets.fromLTRB(14, 16, 14, 16),
      itemCount: _thread.messages.length,
      itemBuilder: (_, i) => _bubble(_thread.messages[i]),
    );
  }

  Widget _bubble(ChatMessage m) {
    if (m.isSystem) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Center(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
            decoration: BoxDecoration(
              color: AppColors.slate200.withOpacity(.4),
              borderRadius: BorderRadius.circular(50),
            ),
            child: Text(m.message,
                style: TextStyle(fontSize: 11, color: AppColors.slate500)),
          ),
        ),
      );
    }
    final mine = m.isResident;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        mainAxisAlignment:
            mine ? MainAxisAlignment.end : MainAxisAlignment.start,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          if (!mine) ...[
            CircleAvatar(
              radius: 14,
              backgroundColor: AppColors.primary,
              child: const Icon(Icons.support_agent,
                  size: 15, color: Colors.white),
            ),
            const SizedBox(width: 8),
          ],
          Flexible(
            child: Column(
              crossAxisAlignment:
                  mine ? CrossAxisAlignment.end : CrossAxisAlignment.start,
              children: [
                Text(mine ? 'You' : 'Barangay Staff',
                    style: TextStyle(
                        fontSize: 9,
                        fontWeight: FontWeight.w800,
                        letterSpacing: .5,
                        color: mine ? AppColors.primary : AppColors.slate400)),
                const SizedBox(height: 3),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  decoration: BoxDecoration(
                    color: mine ? AppColors.primary : Colors.white,
                    borderRadius: BorderRadius.only(
                      topLeft: const Radius.circular(16),
                      topRight: const Radius.circular(16),
                      bottomLeft: Radius.circular(mine ? 16 : 4),
                      bottomRight: Radius.circular(mine ? 4 : 16),
                    ),
                    border: mine
                        ? null
                        : Border.all(color: AppColors.bgBottom),
                    boxShadow: [
                      BoxShadow(
                          color: Colors.black.withOpacity(.04),
                          blurRadius: 6,
                          offset: const Offset(0, 2)),
                    ],
                  ),
                  child: Text(m.message,
                      style: TextStyle(
                          fontSize: 14,
                          height: 1.4,
                          color: mine ? Colors.white : AppColors.slate800)),
                ),
                const SizedBox(height: 3),
                Text(_time(m.timestamp),
                    style:
                        TextStyle(fontSize: 10, color: AppColors.slate400)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _inputBar() {
    return Container(
      padding: EdgeInsets.fromLTRB(
          12, 10, 12, 10 + MediaQuery.of(context).padding.bottom),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: AppColors.bgBottom)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: TextField(
              controller: _input,
              minLines: 1,
              maxLines: 4,
              textInputAction: TextInputAction.newline,
              decoration: InputDecoration(
                hintText: 'I-type ang iyong mensahe…',
                filled: true,
                fillColor: const Color(0xFFF1F5F9),
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(24),
                  borderSide: BorderSide.none,
                ),
              ),
            ),
          ),
          const SizedBox(width: 8),
          GestureDetector(
            onTap: _sending ? null : _send,
            child: Container(
              width: 46,
              height: 46,
              decoration: BoxDecoration(
                color: AppColors.primary,
                shape: BoxShape.circle,
              ),
              child: _sending
                  ? const Padding(
                      padding: EdgeInsets.all(13),
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.send, color: Colors.white, size: 20),
            ),
          ),
        ],
      ),
    );
  }

  void _openHotlines() {
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
              Icon(Icons.emergency, color: AppColors.danger, size: 20),
              const SizedBox(width: 8),
              const Text('Emergency Hotlines',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
            ]),
            const SizedBox(height: 6),
            Text('Pindutin para tumawag (sa totoong device).',
                style: TextStyle(fontSize: 12, color: AppColors.slate400)),
            const SizedBox(height: 16),
            if (_thread.hotlines.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 20),
                child: Text('Wala pang naka-configure na hotline.',
                    style: TextStyle(color: AppColors.slate400)),
              )
            else
              ..._thread.hotlines.map((h) => ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: Container(
                      width: 40,
                      height: 40,
                      decoration: BoxDecoration(
                          color: _hotlineColor(h.color).withOpacity(.15),
                          borderRadius: BorderRadius.circular(12)),
                      child: Icon(Icons.call,
                          color: _hotlineColor(h.color), size: 20),
                    ),
                    title: Text(h.name,
                        style: const TextStyle(
                            fontWeight: FontWeight.w700, fontSize: 14)),
                    subtitle: Text(h.number,
                        style: TextStyle(color: AppColors.slate500)),
                  )),
          ],
        ),
      ),
    );
  }

  Color _hotlineColor(String c) {
    switch (c) {
      case 'red':
        return const Color(0xFFEF4444);
      case 'emerald':
        return const Color(0xFF10B981);
      case 'indigo':
        return const Color(0xFF6366F1);
      case 'amber':
        return const Color(0xFFF59E0B);
      case 'purple':
        return const Color(0xFF8B5CF6);
      case 'rose':
        return const Color(0xFFF43F5E);
      default:
        return const Color(0xFF3B82F6);
    }
  }

  String _time(DateTime? t) {
    if (t == null) return '';
    final h = t.hour % 12 == 0 ? 12 : t.hour % 12;
    final m = t.minute.toString().padLeft(2, '0');
    final ap = t.hour < 12 ? 'AM' : 'PM';
    return '$h:$m $ap';
  }
}
