# My Household — Resident app (Flutter + user backend)

Read-only module, in its own folder like `dashboard/`. Ported from the SOE
resident page `user/household.php` and uses the same tables as the admin
**Household** module.

## What the resident sees

- **Your role:** **Household Head** (`IsHead = 1`), or their relationship to
  the head (`RelationshipToHead`, e.g. Mother, Son) when they are a member
  (`FamilyHeadID` set). Not linked yet → "No household linked, visit the
  barangay office".
- **Household info:** Household ID (from `household_survey`, "Not yet
  assigned" if none), head, house no./street, purok, house type / tenure
  (when recorded), monthly income + class (same brackets as the admin page),
  household survey on file or not, date registered.
- **Summary:** total, male, female, seniors (flag or age 60+), minors, PWD.
- **Members:** the head first (star), then members. Each row shows the photo
  or initials, a **You** tag, relationship pill, sex · age, Senior/PWD tags.
  Tap a person for details (sex, age, birth date, civil status, contact,
  employment, education, voter/senior/PWD/solo parent).
- Everything is view-only. A note says changes go through the barangay office.
- English/Filipino and dark mode like the rest of the app. Relationships are
  translated too (Father → Ama, etc.).

## Files

| File | |
|---|---|
| `flutter_app/lib/household/household_screen.dart` | *new*: the screen |
| `flutter_app/lib/household/household_api.dart` | *new*: models + calls `household.php` |
| `flutter_app/lib/dashboard/dashboard_screen.dart` | the **Household** tile opens the screen |
| `flutter_app/lib/l10n/*` | new EN/FIL strings |
| `user/backend/household.php` | *new*: `GET ?resident_id=` → role, household, members, stats |

## Notes

- `household.php` only reads; it never creates or edits household records,
  and it needs **no SQL changes**.
- Members are residents with `FamilyHeadID` = the head, not deceased, the
  same rule as the admin View Household page.
- Troubleshooting: `http://localhost/CAPS/user/backend/household.php?resident_id=<id>&debug=1`
  shows the exact database error if loading fails.
