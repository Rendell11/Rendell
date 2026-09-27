# Complaint Module — Resident side (Flutter app + user backend)

Ported from the SOE resident page `user/incidents.php` (Complaints tab) and
aligned with the CAPS admin module `admin/complaint/`, so the resident app and
the admin read and write the **same `complaints` rows**.

## Files

| File | What it is |
|---|---|
| `flutter_app/lib/complaint/complaint_screen.dart` | Module entry: summary cards, search, status filter, list, "File a Complaint" button |
| `flutter_app/lib/complaint/complaint_form_screen.dart` | File a complaint: title, category (+ Other), priority, location, description, photo, anonymous, truthfulness check |
| `flutter_app/lib/complaint/complaint_detail_screen.dart` | Status tracker (Naisumite → Inaaksyunan → Naresolba), admin reply, details, attachment |
| `flutter_app/lib/complaint/complaint_api.dart` | HTTP client for `complaint.php` (uses `ApiConfig`, `ApiResult`) |
| `flutter_app/lib/complaint/complaint_model.dart` | `Complaint`, `ComplaintStats`, `ComplaintList`, `ComplaintDraft` |
| `flutter_app/lib/complaint/complaint_widgets.dart` | Shared pills, cards, app bar, colours |
| `flutter_app/lib/dashboard/dashboard_screen.dart` | *changed*: "Incident" tile renamed to **Complaints** and opens the module |
| `user/backend/complaint.php` | *new*: JSON API (same style as `chat.php`) |
| `admin/complaint/backend/update_status.php` | *changed*: admin reply sets `notif_read = 0` (drives the app's "New reply" badge) and adds a `resident_notifications` row |
| `admin/complaint/frontend/complaint_rep.php` | *changed*: anonymous complaints no longer show the resident's name in the admin table |

The whole module lives in its own `lib/complaint/` folder (like `dashboard/`
and `chat/`), so it can be debugged on its own.

## API — `user/backend/complaint.php`

Envelope: `{ success, message, data }`. Every call except `categories` needs
`resident_id` of an **Active** resident, and reads/writes only that
resident's own complaints.

| Action | Method | Params | Returns |
|---|---|---|---|
| `categories` | GET | — | `categories[]`, `priorities[]` |
| `list` | GET | `resident_id` | `complaints[]`, `stats {total,pending,ongoing,resolved,unread}`, `defaults {address_location,purok}` |
| `detail` | GET | `resident_id`, `id` | one complaint; marks it read |
| `submit` | POST (multipart) | `resident_id`, `category`, `other_category_specify`, `title`, `description`, `address_location`, `priority_level`, `is_anonymous` (0/1), `attachment` (optional file) | `id`, `complaint_id` |

Rules (same as the admin form):
- `complaint_id` = `CMP-YYYYMMDD-####` (checked for uniqueness).
- Categories: Noise Complaint, Garbage/Sanitation, Property Dispute,
  Harassment, Domestic Issue, Road/Infrastructure, Public Safety, Other.
  "Other" is stored under the resident's own label (like
  `process_complaint.php`), so it appears in the admin chart.
- Priority: Low / Medium / High (Urgent). Status is set only by the admin.
- Attachment: JPG/PNG/WEBP/GIF/HEIC or PDF, max 5 MB, saved to
  `user/backend/uploads/complaints/`.
- `purok` and `complainant_name` come from the resident's record. When the
  complaint is anonymous, `complainant_name` is stored as NULL.
- Each complaint is also written to `activity_logs` (module "Complaints", role "Resident").

## Database

**No manual SQL is needed.** The API checks `complaints` for the columns it
uses and adds any that are missing: `notif_read`, `other_category_specify`,
`is_anonymous`, `purok`. The `barangay_db` dump already has all of them.

## Setup

1. Copy `user/backend/complaint.php` into `htdocs\CAPS\user\backend\`, and
   make sure `user/backend/uploads/` is writable.
2. Replace `admin/complaint/backend/update_status.php` and
   `admin/complaint/frontend/complaint_rep.php`.
3. Copy `flutter_app/lib/complaint/` and the updated
   `flutter_app/lib/dashboard/dashboard_screen.dart` into your app. No new
   packages are needed (it uses `http` and `image_picker`, which the app
   already has).
4. `flutter run`. Open the app, log in, then tap **Complaints** on the
   dashboard or in the drawer.

## Notes / follow-ups

- The trust model is the same as `chat.php`: the app sends `resident_id`.
  A server-issued token for all app endpoints would be a good later hardening step.
- The admin complaint modal does not show attachments yet. Resident photos are
  saved and linked in `attachment_path`, and can be added to that modal later.
- The dashboard's notification bell is still a placeholder. It can read
  `resident_notifications` (the admin reply now writes there) once the
  notifications module is built.
