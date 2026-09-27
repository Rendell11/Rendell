/// One message in the resident ⇄ barangay staff chat (emergency_chats row).
class ChatMessage {
  final int id;
  final String senderRole; // Resident | Staff | System
  final String message;
  final String? category;
  final String? imagePath;
  final String status;
  final DateTime? timestamp;

  const ChatMessage({
    required this.id,
    required this.senderRole,
    required this.message,
    this.category,
    this.imagePath,
    this.status = 'Pending',
    this.timestamp,
  });

  bool get isResident => senderRole.toLowerCase() == 'resident';
  bool get isSystem => senderRole.toLowerCase() == 'system';

  factory ChatMessage.fromJson(Map<String, dynamic> j) => ChatMessage(
        id: int.tryParse(j['ChatID']?.toString() ?? '') ?? 0,
        senderRole: (j['SenderRole'] ?? 'Resident').toString(),
        message: (j['Message'] ?? '').toString(),
        category: j['Category']?.toString(),
        imagePath: j['ImagePath']?.toString(),
        status: (j['Status'] ?? 'Pending').toString(),
        timestamp: DateTime.tryParse(j['Timestamp']?.toString() ?? ''),
      );
}

/// An emergency hotline (emergency_hotlines row).
class Hotline {
  final String name;
  final String number;
  final String color;
  const Hotline({required this.name, required this.number, this.color = 'blue'});

  factory Hotline.fromJson(Map<String, dynamic> j) => Hotline(
        name: (j['name'] ?? '').toString(),
        number: (j['number'] ?? '').toString(),
        color: (j['color'] ?? 'blue').toString(),
      );
}

/// The full chat state returned by `chat.php?action=list`.
class ChatThread {
  final List<ChatMessage> messages;
  final String status;
  final bool canStartNew;
  final List<Hotline> hotlines;

  const ChatThread({
    this.messages = const [],
    this.status = 'None',
    this.canStartNew = true,
    this.hotlines = const [],
  });

  factory ChatThread.fromJson(Map<String, dynamic> j) => ChatThread(
        messages: (j['messages'] as List? ?? [])
            .map((e) => ChatMessage.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
        status: (j['status'] ?? 'None').toString(),
        canStartNew: j['can_start_new'] == true || j['can_start_new'] == 1,
        hotlines: (j['hotlines'] as List? ?? [])
            .map((e) => Hotline.fromJson(Map<String, dynamic>.from(e)))
            .toList(),
      );
}
