# Security, Notifications, Alerts & Push — Resident app

## 1. Login tokens (API security)

Before, every endpoint trusted the `resident_id` the app sent, so anyone could
read or change another resident's data by editing it. Now:

- `login.php` returns a **token** (random, 64 hex). Only its SHA-256 hash is
  stored, in the new table `resident_sessions` (created automatically).
- The app saves it in the secure store and sends it on every request as the
  `X-Auth-Token` header (`Authorization: Bearer …` also works).
- Every resident endpoint calls `require_resident()` (`user/backend/auth.php`):
  the resident is taken **from the token**; the `resident_id` sent by the app
  is ignored. No/expired/revoked token → HTTP 401 → the app signs out and
  shows "Your session has expired. Please log in again."
- Tokens expire after 30 days without use (renewed while the app is used).
  The app's own "Remember me for 7 days" still applies.
- `logout.php` ends the token. Settings → Security → **Log out on all
  devices** ends every token of the resident.
- Changing the password logs out every other device.
- A resident whose account is set to Disabled (or Deceased) is signed out on
  the next request.

Public endpoints stay open: `officials.php`, `theme.php`, `puroks.php`,
`address.php`, and the pre-login ones (`login`, `request_access`,
`check_status`, `forgot_password`, `set_password`).

**After updating, every resident must log in once** (sessions saved before
the update have no token).

## 2. Notifications — `flutter_app/lib/notifications/`

The bell now shows the real unread count and opens a Notifications screen
(`user/backend/notifications.php`). It lists:

- updates written by the admin in `resident_notifications` (document ready /
  rejected / released, complaint status updates)
- new announcements (last 30 days) — reading one also clears its NEW badge
- disaster alerts
- blotter case updates for cases the resident is part of (hearing scheduled,
  notice issued, resolved …) and a **reminder the day before a hearing**

Tapping one marks it read and opens the related screen (request, complaint,
announcement, blotter case, alerts). "Mark all as read" in the top bar.

## 3. Alerts & Hazard Map — `flutter_app/lib/disaster/`

From the admin "Disaster and Risk Map" (`disaster_alerts`, `hazards`),
through `user/backend/disaster.php`:

- **Dashboard banner** for every active alert (tap → Alerts).
- **Alerts tab:** active alerts (message, affected area, evacuation center,
  "View on map"), then the last 30 days.
- **Hazard Map tab:** OpenStreetMap (no API key) with the flood / fire /
  structural / earthquake zones, Safe Points, the active alert area and the
  resident's **home pin** (from the household). Tap a marker for details.

New packages: `flutter_map`, `latlong2` → run `flutter pub get`.

## 4. Dashboard

The dead "Reservations" card (no equipment module) now shows **open
complaints** (Pending + Ongoing). New tile: **Alerts & Hazard Map**.
Pull down to refresh the whole dashboard.

## 5. Push notifications (Firebase) — optional, off until set up

Everything above works without Firebase. To also get phone notifications:

**Server**
1. Create a Firebase project → Project settings → Service accounts →
   *Generate new private key*.
2. Save the file as `user/backend/private/firebase_service_account.json`.
   The `private/` folder is blocked from the web by its `.htaccess` — never
   put the key anywhere else, and never commit it.
3. Windows Task Scheduler, every 1 minute:
   `C:\xampp\php\php.exe C:\xampp\htdocs\CAPS\user\backend\push_worker.php`
   (Also runs by itself whenever a resident opens the app, as a fallback.)

What gets pushed (once each, only items from the last 24 hours):
document/complaint updates → that resident; disaster alerts with **Notify
app** ticked → everyone; new announcements → everyone; blotter updates and
the hearing reminder → the parties of the case.

**App** (no `google-services.json` needed):
Firebase console → Project settings → General → your app → copy the values:

```
flutter run --dart-define=API_BASE_URL=http://<pc-ip>/CAPS/user/backend \
  --dart-define=FIREBASE_API_KEY=... \
  --dart-define=FIREBASE_APP_ID=... \
  --dart-define=FIREBASE_SENDER_ID=... \
  --dart-define=FIREBASE_PROJECT_ID=...
```

Web (Chrome) also needs `--dart-define=FIREBASE_VAPID_KEY=...` (Cloud
Messaging → Web Push certificates) and `web_push/firebase-messaging-sw.js`
copied into `flutter_app/web/` with the same values.

The app asks for notification permission after login and sends the device
token to `notifications.php` (`register_device`).

## Files

| File | |
|---|---|
| `user/backend/auth.php` | *new*: tokens, `require_resident()` |
| `user/backend/logout.php` | *new* |
| `user/backend/notifications.php` | *new* |
| `user/backend/disaster.php` | *new* |
| `user/backend/push_lib.php`, `push_worker.php`, `private/` | *new*: push |
| `user/backend/config.php`, `login.php`, `change_password.php` and every resident endpoint | use the token |
| `flutter_app/lib/services/auth_client.dart`, `push_service.dart` | *new* |
| `flutter_app/lib/notifications/`, `lib/disaster/` | *new* modules |
| `flutter_app/lib/config/api_config.dart`, `services/session_service.dart`, `main.dart`, `dashboard/`, `settings/` | token, 401 handling, dashboard, log out all devices |
