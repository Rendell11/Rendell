# Officials & Announcements — Resident app (Flutter + user backend)

Two read-only modules. Each has its own folder, like `dashboard/`.

## Officials — `flutter_app/lib/officials/`

- Same list and order as the admin **Officials** page: only current officials
  (TermEnd empty or today/future), Punong Barangay first, then
  Secretary/Treasurer, Kagawads (with committee), SK Chairperson.
- Punong Barangay in a large card; the rest in a grid with photo or initials.
- Tap an official to see their position, committee and term.
- Photos come from what the admin uploaded (`admin/officials/uploads/officials/`).

| File | |
|---|---|
| `officials_screen.dart` | The screen |
| `officials_api.dart` | `Official` model + calls `officials.php` |
| `user/backend/officials.php` | *new*: GET → current officials |

## Announcements — `flutter_app/lib/announcements/`

Ported from the SOE resident page `user/announcements.php`.

- Only **Published**, not-deleted announcements (Draft/Scheduled stay hidden
  until the admin or the scheduler publishes them). **Emergency Notices on
  top**, then newest.
- Search, category filter, **NEW** badge until the resident opens it, and an
  **Ended** tag when `date_end` has passed.
- Card: cover photo, category, title, preview, posted date, schedule
  (dates and times), attachment count.
- Detail: swipeable photos (tap to zoom), full text (selectable), schedule,
  and the list of other attached files.
- **Dashboard:** the "Latest Announcements" card now shows the 3 newest
  (tap to open), with a **View all** button; the Announcements and Officials
  tiles open the modules.

| File | |
|---|---|
| `announcements_screen.dart` | List |
| `announcement_detail_screen.dart` | Full announcement |
| `announcement_api.dart` | Models + calls `announcements.php` |
| `announcement_widgets.dart` | Category colors/icons, pills, image |
| `user/backend/announcements.php` | *new*: `list` (resident_id), `read` (resident_id, id) |

### "New" badge

The SOE portal remembered opened announcements only in the browser
session. The app stores them per resident in a new table
`announcement_reads (resident_id, announcement_id, read_at)`.
`announcements.php` creates it automatically (**no manual SQL**).

## Notes

- Everything is in English/Filipino and follows dark mode, like the rest of the app.
- Officials' photos and announcement images also show on Flutter web (same
  `<img>` fallback used for profile photos).
- Non-image attachments (e.g. PDF) are listed by name and size. Opening
  them from the app would need a package like `url_launcher` (not added yet).
