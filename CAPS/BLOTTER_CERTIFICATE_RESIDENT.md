# Blotter & Certificates — Resident app (Flutter + user backend)

Two modules, each in its own folder like `dashboard/`. Both follow the SOE
resident process and read the same tables as the admin modules.

## Blotter / Incidents — `flutter_app/lib/blotter/` (view only)

Ported from the Blotter tab of SOE `user/incidents.php`.

- Blotter reports are **not filed online** (same as SOE). The screen tells the
  resident to go to the Barangay Hall.
- Lists the cases where the resident is a **complainant or respondent**
  (`blotter_parties.resident_id`, or the primary ComplainantID/RespondentID of
  older SOE cases), with status, "You are the Respondent/Complainant", incident
  date/place and the **next hearing**. Filter: all / active.
- Case details: incident, what happened, parties (names and role only, no
  contact numbers or addresses of others), hearings and results, **notices
  issued to the resident** (e.g. Summons), resolution, transfer, case history.

| File | |
|---|---|
| `blotter_screen.dart` | List |
| `blotter_detail_screen.dart` | One case |
| `blotter_api.dart` | Models, status colours, calls `blotter.php` |
| `user/backend/blotter.php` | *new*: `list`, `detail` (only cases the resident is part of) |

## Documents & Certificates — `flutter_app/lib/certificate/`

Ported from SOE `user/legal_docu.php` + `legal_docu_handler.php`, built on the
admin module itself: `certificate.php` loads
`admin/certificates/backend/cert_common.php`, so statuses, the reference number
(`REF-YYYY-####`), the status log and the 15-day expiry are exactly the admin's.

- **Request a document:** the types, requirements and extra information fields
  are the ones the admin set up (active, not draft). The resident checks the
  requirements they have (optional photo per requirement), fills the extra
  fields (text / number / date / textarea / select, required ones checked),
  and writes the purpose.
- Heads-up before sending: active blotter cases and documents not picked up
  (what the staff will see on review).
- SOE limits: max 5 pending requests; one pending request per document type.
- **My requests:** Pending → Under review → Ready to pick up (with "pick up
  until" date) → Released, or Rejected (with reason) / Expired. Filter chips,
  red dot when the barangay updated a request, status history, and **Cancel**
  while still Pending.
- **Dashboard:** "Request Document" opens it; the stat cards (total, approved,
  pending), Recent Requests and Request Summary now show real data. A new
  **Blotter** tile opens the blotter module.

| File | |
|---|---|
| `certificate_screen.dart` | My requests (+ `CertRequestTile`, also on the dashboard) |
| `certificate_form_screen.dart` | Choose a document → form |
| `certificate_detail_screen.dart` | One request, history, cancel |
| `certificate_api.dart` | Models, status colours, calls `certificate.php` |
| `user/backend/certificate.php` | *new*: `types`, `list`, `detail`, `notices`, `submit`, `cancel` |

Requirement photos are saved in `user/backend/uploads/document_requests/` and
recorded in `document_request_files` with the path relative to `CAPS/`, so the
admin Review panel shows them.

## Notes

- No SQL to run: `certificate.php` uses the admin's self-healing schema; the
  blotter tables are in `barangay_db_MERGED_SEPT27.sql`.
- Needs the admin files in place: `admin/certificates/backend/cert_common.php`,
  `admin/id_helper.php`, `admin/activity_log_helper.php`.
- If a screen cannot load, open the endpoint with `debug=1`, e.g.
  `http://localhost/CAPS/user/backend/certificate.php?action=types&resident_id=<id>&debug=1`.
