# Profile Module — Resident app (Flutter + user backend)

"My Profile" for the resident, ported from the SOE page `user/profile.php`.

- **Profile picture:** the resident can take a photo, pick one from the
  gallery, or remove it (back to initials). The picture also shows on the
  dashboard (top bar, drawer, welcome card) and on the Settings profile card.
- **Details are VIEW ONLY** for now. A note tells the resident to visit the
  Barangay Hall for corrections. Nothing in the details can be edited from the app.

Open it from Dashboard → profile menu → **My Profile**, the drawer, the
welcome-card avatar, or the profile card at the top of **Settings**.

## What is shown

| Section | Fields (from `residents`) |
|---|---|
| Header | Photo, full name, resident code |
| Personal Information | Full name, sex, date of birth, age, place of birth, civil status, religion, nationality |
| Contact Information | Email, contact number |
| Address | House no. / street, purok / area |
| Household | Head of the family, relationship to head, household head's name |
| Work & Education | Employment, education, household income |
| Sectors & Benefits | Voter, PWD (+ classification), senior citizen, solo parent, PhilHealth, SSS/GSIS, 4Ps |
| Account | Resident code, registered since |

Labels are in English/Filipino like the rest of the app. Common values
(Male, Single, Unemployed, Head of Family…) are translated in Filipino.

## Files

| File | What it is |
|---|---|
| `flutter_app/lib/profile/profile_screen.dart` | The Profile screen |
| `flutter_app/lib/profile/profile_avatar.dart` | Round photo/initials avatar (reused by dashboard + settings) |
| `flutter_app/lib/profile/profile_api.dart` | Calls `profile.php` |
| `flutter_app/lib/profile/profile_model.dart` | `ResidentProfile` |
| `flutter_app/lib/dashboard/dashboard_screen.dart` | *changed*: My Profile opens Profile; avatars show the photo |
| `flutter_app/lib/settings/settings_screen.dart` | *changed*: profile card shows the photo and opens Profile |
| `flutter_app/lib/l10n/*` | *changed*: profile strings (EN/FIL) |
| `user/backend/profile.php` | *new*: `get`, `upload_photo`, `remove_photo` |

## API — `user/backend/profile.php`

| Action | Method | Params | Notes |
|---|---|---|---|
| `get` | GET | `resident_id` | Details + `photo_url` (relative to `user/backend/`) |
| `upload_photo` | POST multipart | `resident_id`, `photo` | JPG/PNG/WEBP, max 5 MB; replaces (and deletes) the old photo |
| `remove_photo` | POST | `resident_id` | Sets `ProfilePhoto` to NULL and deletes the file |

Only **Active** residents are allowed. Photos are saved in
`user/backend/uploads/profile_photos/` as `resident_<id>_<random>.<ext>`.
`residents.ProfilePhoto` stores the path relative to the CAPS root, e.g.
`user/backend/uploads/profile_photos/resident_14_ab12cd34ef56.jpg`, so the
admin side can show it as `CAPS/<ProfilePhoto>`.

**No SQL needed.** `residents.ProfilePhoto` already exists.

## Later (not built yet)

Editing details from the app. When it's decided which fields a resident may
change (for example, contact number and email), add an `update` action to
`profile.php` and edit forms to the screen.
