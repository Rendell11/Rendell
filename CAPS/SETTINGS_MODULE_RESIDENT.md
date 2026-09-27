# Settings Module — Resident app (Flutter + user backend)

The resident's own app settings, plus English/Filipino for the **whole app**
(no more hard-coded Tagalog) and a dark mode that every screen follows.

## What the resident sees (Dashboard → menu/drawer → Settings)

| Section | Items |
|---|---|
| Profile | Name, resident code, email, contact, purok |
| Appearance | **Theme**: Light / Dark / Auto (follows the phone) · **App color**: barangay default + 11 colors · **Text size**: Small / Normal / Large · live preview |
| Language | **English** / **Filipino**: the whole app, including messages from the server |
| Security | Change password · Change/set up PIN · Fingerprint/face unlock on/off · "Stay signed in until …" |
| About | App version · Need help? · Reset appearance to the barangay defaults |
| Log out | |

Changes apply **immediately** on every screen. They are saved on the device,
so they already apply on the login screen, and synced to the server so they
follow the resident to another phone. The login and Request Access screens
also have an **EN | FIL** toggle, because residents can't reach Settings
before they log in.

Defaults: Filipino. Theme and color follow what the admin set for the portal
(`settings.php` → `portal_preferences`) until the resident picks their own.

## Files

| File | What it is |
|---|---|
| `flutter_app/lib/settings/settings_screen.dart` | The Settings screen |
| `flutter_app/lib/settings/change_password_screen.dart` | Change password |
| `flutter_app/lib/settings/change_pin_screen.dart` | Change / set up the 6-digit PIN |
| `flutter_app/lib/settings/app_settings.dart` | Preference store: language, theme, color, text size; device + server sync; live apply |
| `flutter_app/lib/settings/settings_api.dart` | Calls `preferences.php` and `change_password.php` |
| `flutter_app/lib/l10n/app_text.dart` | Every UI string (`tr.xxx`), abstract |
| `flutter_app/lib/l10n/app_text_en.dart` / `app_text_fil.dart` | English / Filipino text |
| `flutter_app/lib/widgets/language_toggle.dart` | EN / FIL pill (login, request access) |
| `flutter_app/lib/theme/app_theme.dart` | *changed*: colors follow dark mode + chosen color; new surface/status colors |
| `flutter_app/lib/main.dart` | *changed*: loads settings, applies theme/locale/text size, refreshes on change |
| all screens | *changed*: text → `tr.xxx`, hard-coded white/light colors → `AppColors.*` |
| `flutter_app/pubspec.yaml` | *changed*: `flutter_localizations` (date picker etc. in Filipino) |
| `user/backend/preferences.php` | *new*: GET/POST the resident's app preferences |
| `user/backend/change_password.php` | *new*: change password (checks the current one) |
| `user/backend/config.php` | *changed*: `app_lang()` / `L('Filipino', 'English')`; CORS allows `X-App-Lang` |
| `user/backend/lib.php`, `chat.php`, `complaint.php`, other endpoints | *changed*: messages via `L()` so they follow the app language |

## How the language works

- **App:** screens use `tr.someText`. To add or change a string, edit
  `app_text.dart` and **both** `app_text_en.dart` and `app_text_fil.dart`
  (the compiler errors if one language is missing).
- **Server:** the app sends `X-App-Lang: en|fil` on every request, and PHP
  answers with `L('Filipino', 'English')`. Browser pages can pass `?lang=en`.
  The default is Filipino, so existing web pages are unchanged.

## Database

No manual SQL. Preferences go in the existing `user_preferences` table
(`user_id` = `residents.ResidentID`, the same table the SOE resident web
portal uses). Keys are prefixed `app_` so they don't clash with the web
portal's keys:

| key | values |
|---|---|
| `app_language` | `en`, `fil` |
| `app_theme_mode` | `default`, `light`, `dark`, `system` |
| `app_accent` | `default`, `#RRGGBB` |
| `app_text_size` | `small`, `normal`, `large` |

`preferences.php` creates the table if a DB doesn't have it.

## Setup

1. Copy `user/backend/*.php` into `htdocs\CAPS\user\backend\`.
2. Copy `flutter_app/lib/` and `pubspec.yaml`, then run `flutter pub get`.
3. `flutter run`. Log in, open the menu, then tap **Settings**.

## Notes

- Same trust model as the other app endpoints (the app sends `resident_id`).
  A server-issued token would be the next hardening step for all of them.
- The notification bell's "99+" on the dashboard is still a placeholder from
  the original dashboard.
