/// One row of the `complaints` table as returned by
/// `user/backend/complaint.php` (see `complaint_row()` there).
class Complaint {
  final int id;
  final String complaintId; // CMP-YYYYMMDD-####
  final String title;
  final String category;
  final String? otherCategory;
  final String description;
  final String location;
  final String? purok;
  final String priority; // Low | Medium | High (Urgent)
  final bool isAnonymous;
  final String status; // Pending | Ongoing | Resolved
  final String adminReply;
  final String? attachmentUrl; // relative to ApiConfig.baseUrl
  final bool isUnread;
  final DateTime? createdAt;

  const Complaint({
    required this.id,
    required this.complaintId,
    required this.title,
    required this.category,
    this.otherCategory,
    required this.description,
    required this.location,
    this.purok,
    this.priority = 'Medium',
    this.isAnonymous = false,
    this.status = 'Pending',
    this.adminReply = '',
    this.attachmentUrl,
    this.isUnread = false,
    this.createdAt,
  });

  bool get hasReply => adminReply.trim().isNotEmpty;
  bool get hasAttachment => (attachmentUrl ?? '').isNotEmpty;
  bool get attachmentIsPdf =>
      (attachmentUrl ?? '').toLowerCase().endsWith('.pdf');

  factory Complaint.fromJson(Map<String, dynamic> j) => Complaint(
        id: int.tryParse(j['id']?.toString() ?? '') ?? 0,
        complaintId: (j['complaint_id'] ?? '').toString(),
        title: (j['title'] ?? '').toString(),
        category: (j['category'] ?? '').toString(),
        otherCategory: j['other_category_specify']?.toString(),
        description: (j['description'] ?? '').toString(),
        location: (j['address_location'] ?? '').toString(),
        purok: j['purok']?.toString(),
        priority: (j['priority_level'] ?? 'Medium').toString(),
        isAnonymous: j['is_anonymous'] == true || j['is_anonymous'] == 1,
        status: (j['status'] ?? 'Pending').toString(),
        adminReply: (j['admin_reply'] ?? '').toString(),
        attachmentUrl: j['attachment_url']?.toString(),
        isUnread: j['is_unread'] == true || j['is_unread'] == 1,
        createdAt: DateTime.tryParse(j['created_at']?.toString() ?? ''),
      );
}

/// Counts shown on the summary cards.
class ComplaintStats {
  final int total;
  final int pending;
  final int ongoing;
  final int resolved;
  final int unread;

  const ComplaintStats({
    this.total = 0,
    this.pending = 0,
    this.ongoing = 0,
    this.resolved = 0,
    this.unread = 0,
  });

  factory ComplaintStats.fromJson(Map<String, dynamic> j) {
    int n(String k) => int.tryParse(j[k]?.toString() ?? '') ?? 0;
    return ComplaintStats(
      total: n('total'),
      pending: n('pending'),
      ongoing: n('ongoing'),
      resolved: n('resolved'),
      unread: n('unread'),
    );
  }
}

/// Everything `complaint.php?action=list` returns.
class ComplaintList {
  final List<Complaint> complaints;
  final ComplaintStats stats;

  /// Pre-fill for the form: the resident's own address from `residents`.
  final String defaultLocation;

  const ComplaintList({
    this.complaints = const [],
    this.stats = const ComplaintStats(),
    this.defaultLocation = '',
  });

  factory ComplaintList.fromJson(Map<String, dynamic> j) {
    final defaults = j['defaults'] is Map
        ? Map<String, dynamic>.from(j['defaults'])
        : const <String, dynamic>{};
    return ComplaintList(
      complaints: (j['complaints'] as List? ?? [])
          .map((e) => Complaint.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
      stats: j['stats'] is Map
          ? ComplaintStats.fromJson(Map<String, dynamic>.from(j['stats']))
          : const ComplaintStats(),
      defaultLocation: (defaults['address_location'] ?? '').toString(),
    );
  }
}

/// A new complaint as filled in on the form.
class ComplaintDraft {
  final String category;
  final String otherCategory;
  final String title;
  final String description;
  final String location;
  final String priority;
  final bool isAnonymous;

  const ComplaintDraft({
    required this.category,
    this.otherCategory = '',
    required this.title,
    required this.description,
    required this.location,
    required this.priority,
    this.isAnonymous = false,
  });

  Map<String, String> toFields(int residentId) => {
        'action': 'submit',
        'resident_id': '$residentId',
        'category': category,
        'other_category_specify': otherCategory,
        'title': title,
        'description': description,
        'address_location': location,
        'priority_level': priority,
        'is_anonymous': isAnonymous ? '1' : '0',
      };
}

/// Same lists as the admin "Add New Complaint" form (complaint_rep.php), used
/// when the server's `categories` action can't be reached.
const List<String> kComplaintCategories = [
  'Noise Complaint',
  'Garbage/Sanitation',
  'Property Dispute',
  'Harassment',
  'Domestic Issue',
  'Road/Infrastructure',
  'Public Safety',
  'Other',
];

const List<String> kComplaintPriorities = ['Low', 'Medium', 'High (Urgent)'];
