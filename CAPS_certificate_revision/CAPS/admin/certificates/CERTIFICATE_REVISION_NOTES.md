# Certificate Module Revision — CAPS

The SOE certificate module (`admin_int/legal_docu.php`, `add_doc_modal.php`,
`certificate_edit_template.php`, `process_certificate_template.php`, …) was rebuilt inside CAPS
under `admin/certificates/` following `SOE_CERTIFICATE_REVISION.md` (parts A–F). The Sidebar link
`/CAPS/admin/certificates/frontend/legal_docu.php` already pointed here.

## How to install
1. Copy the `CAPS/` folder from this package over your `C:\xampp\htdocs\CAPS` (it only adds/replaces the files listed below).
2. Open **Certificates** once — the tables are created automatically. (Or run `admin/certificates/certificate_revision.sql` in phpMyAdmin.)
3. **Gemini key:** make a NEW key in Google AI Studio (the old one is in the source code/zip, so treat it as leaked and delete it),
   then put it in `CAPS/.env`: `GEMINI_API_KEY=...` (optional `GEMINI_MODEL=`). `.env` is already blocked from the web by the root `.htaccess`.
4. Templates → **Add Document** → finish the 5 steps before issuing.

## Files
| File | Why |
|---|---|
| `admin/id_helper.php` (new) | A. `next_record_id($pdo,'DOC')` → `DOC-2026-0001`; table `id_sequences`; seeded from existing numbers; never reused. |
| `admin/ai_helper.php` (new) | One Gemini client: key only from `.env`, model fallback `GEMINI_MODEL` → `gemini-flash-lite-latest` → `gemini-flash-latest` (moves on at 0/404/429/500/503), 35 s timeout, joins non-thought parts, md5 snapshot cache. |
| `admin/announcement/backend/ai_config.php` (changed) | **Hardcoded key removed.** Same function names (`gemini_api_key`, `gemini_generate`), now calls `ai_helper.php`, so disaster/announcement/resident AI summaries use the same helper. |
| `admin/db.php` (changed) | `date_default_timezone_set('Asia/Manila')` + `SET time_zone='+08:00'` on PDO and mysqli; mysqli charset utf8mb4. |
| `admin/certificates/backend/cert_common.php` (new) | Schema (self-healing), status rules, CSRF, permissions, field catalog from `residents`, old→new field keys, eligibility, blotter check, 15-day expiry, status log, notifications, render snapshot. |
| `admin/certificates/backend/cert_actions.php` (new) | Main page JSON: list/counts, live resident search, eligibility, blotter case, walk-in save, review/accept/reject, per-document layout, Print & Release. Status rules enforced server-side. |
| `admin/certificates/backend/template_actions.php` (new) | Template Builder JSON: save draft/steps, paper size, requirements, extra fields, image upload (PNG/JPG/WebP ≤5 MB), layout save/finish, enable/disable/delete. |
| `admin/certificates/backend/cert_ai_detect.php` (new) | AI Auto Detect (POST, CSRF): template image + fields → suggested positions; applied in the editor only, not saved. |
| `admin/certificates/backend/print_certificate.php` (new) | Certificate at its paper size (`@page{size;margin:0}`); prints once via a one-time token from Print & Release, otherwise view only. |
| `admin/certificates/backend/cert_analytics_data.php` (new) | F. All analytics numbers, rule-based insights, aggregated AI snapshot. |
| `admin/certificates/backend/cert_ai_analytics.php` (new) | F. AI Analytics Overview JSON (`?cached_only=1`, `?refresh=1`, fallback insights). |
| `admin/certificates/backend/cert_analytics_report.php` (new) | F. Print / PDF report (letterhead, cards, sections with explanations, optional AI sections, certification, signatures). |
| `admin/certificates/frontend/legal_docu.php` (new) | Main page: cards + online bubble (45 s polling), tabs Pending · Online Queue · Released · Expired · Walk-in · All, 6-step Issue Walk-In, Preview (Edit / Print & Release), Review (Accept / Reject), View + status log, blotter pop-up. |
| `admin/certificates/frontend/document_templates.php` (new) | Template Builder list (Not finished badge) + wizard steps 1–4 + step 5 layout editor. |
| `admin/certificates/frontend/certificate_analytics.php` (new) | F. Analytics page. |
| `admin/certificates/frontend/partials/cert_head.php` (new) | Shared head/styles/helpers (toast, confirm, CSRF fetch). |
| `admin/certificates/frontend/assets/cert_render.js` (new) | One renderer for preview, editor and print (preview = print). |
| `admin/certificates/frontend/assets/cert_editor.js`, `cert_editor.css` (new) | Drag-and-drop editor: live preview left, searchable grouped field panel right, property bar (size, bold, align, width, UPPERCASE, color, remove). |
| `admin/certificates/certificate_revision.sql` (new) | Every schema change as SQL (optional — the module does this itself). |
| `upload/certificates/.htaccess` (new) | No scripts can run from the upload folder. |

## Decisions you should know
- **Custom Layout removed.** Old documents that used it keep printing their saved blocks read-only until you save a layout for them in the editor.
- **Verified** (walk-in step 4 / review): CAPS has no "verified" column, so Verified = complete registered profile (Resident ID, name, birth date, sex, address) and portal account not Disabled. Missing items are listed.
- **Active blotter** = case status Filed, Pending, Scheduled, Under Conciliation, Under Mediation, Eligible for Transfer or Transfer Approved (as Complainant or Respondent). Proceeding stores who proceeded.
- **Reference numbers** now use the same helper: `REF-2026-0001` (old `#REF-…` rows keep their numbers).
- Document numbers are assigned on Generate (walk-in) or Accept (online) — not on submission.
- Optional applicant photo on walk-in step 5 was kept from the old form.
- Other modules' ID generators (not changed, listed for later): `residents/backend/residents.php` (RES, COUNT-based), `household/backend/household_common.php` (HH), `staffbpso/backend/process_staff.php` (STF, COUNT-based), `announcement/backend/process_disaster.php` (DIS), `announcement_analytics_data.php` / `get_trash.php` (ANN).

## Found while testing (outside this module)
- `Rebuild_Database.txt` stops at line 980: `ALTER TABLE sms_recipient_logs` runs before that table exists (it is only created at runtime by `sms_recipient_log.php`).
- The Officials/Staff revision is unzipped one folder too deep (`admin/officials/officials/…`). `staffbpso/backend/staff_analytics_report.php` needs `admin/officials/backend/report_common.php`. The certificate report looks in both places.
