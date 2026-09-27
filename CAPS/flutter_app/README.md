# Barangay Biñang 2nd — Resident Portal (Flutter + PHP API)

The resident **request-access / login / forgot-password** flow from your SOE
portal, rebuilt as a **Flutter app** whose UI matches the SOE web pages
(`request_access.php`, `resident_login.php`, `resident_forgot_password.php`),
on top of a small **PHP REST API** that uses your `barangay_db`
(`access_requests` + `residents`, exactly as the web app does).

## What goes where

```
web_caps_user/        →  copy CONTENTS into  C:\xampp\htdocs\CAPS\user\
├── backend/          →  JSON API (called by the Flutter app)
│   ├── config.php        ← set DB credentials
│   ├── lib.php           ← shared logic (source of truth)
│   ├── request_access.php  check_status.php  set_password.php
│   ├── login.php  forgot_password.php  puroks.php
│   └── uploads/          ← valid-ID / selfie images (make writable)
└── frontend/         →  optional browser test pages
flutter_app/          →  the mobile app (keep OUTSIDE htdocs)
```

## Matching UI (design taken from your SOE CSS)
- **Poppins** font (via `google_fonts`), brand blue `#1D63DA`, navy `#0C3E90`.
- Animated **wave** background, white **glass cards**, blue divider, badge pills.
- **Login** screen = home: login card + **Track Request Status** + Request Access link + Forgot password.
- **Request Access**: hero card with **EN/FIL toggle**, 3 section cards
  (Personal Information · Address in Barangay · Verification Requirement),
  valid-ID + selfie upload, and the "What happens next" steps, then the
  submitted success state — same as SOE.
- **Forgot Password** and **Set Password** screens included.
- Your **barangay logo** is bundled at `assets/barangaylogo.webp`.

## Setup

**Backend (XAMPP):**
1. Import the Rebuild Database script so `barangay_db` exists.
2. Copy `backend/` and `frontend/` into `htdocs\CAPS\user\`.
3. Edit `backend/config.php` → `DB_USER` / `DB_PASS`.
4. (optional) browser test: `http://localhost/CAPS/user/frontend/index.php`

**Flutter app:**
```
cd flutter_app
flutter pub get
flutter run                                   # Android emulator → 10.0.2.2
flutter run -d chrome --dart-define=API_BASE_URL=http://localhost/CAPS/user/backend
```
Set the API base URL in `lib/config/api_config.dart` or via `--dart-define`.
`google_fonts` fetches Poppins on first run (needs internet once); it falls
back to a system font offline.

## Appearance follows the admin (pre-login screens)
The **Login, Request Access and Forgot Password** screens use the accent colour
the admin sets in **settings.php** (stored in `admin_preferences`:
`accent_color`, `color_mode`). The app reads it from `backend/theme.php` at
startup and applies the accent across buttons, header, badges and waves. Falls
back to brand blue `#1D63DA` if unset or offline. (Per-resident appearance
*inside* the app is a planned follow-up, not built yet.)

## Address is admin-managed (not hard-coded)
The request form's **Address in Barangay** section is filled from what the
admin configured in **Manage Area** — never hard-coded:
- Region / Province / City-Municipality / Barangay come from
  `barangay_profile` (set via the admin PSGC picker) and show as **read-only**.
- **Street** dropdown ← `resident_streets` (Active) for that barangay.
- **Purok / Area** dropdown ← `resident_areas` (Active), labelled with its type.

The app reads these from `backend/address.php`
(`?action=profile | streets | areas`), which mirrors your
`admin/backend/address_api.php` but is public (no admin session). So whatever
the admin adds/edits in Manage Area shows up in the app automatically. If the
default address isn't configured yet, the form shows a warning and blocks
submit.

## Stay-signed-in (bank-app style)
After a successful login with **"Remember me for 7 days"** checked, the app:
1. Saves the session in the device's **secure store** (Android Keystore / iOS
   Keychain) for 7 days.
2. Asks the resident to set a **6-digit PIN**, and to optionally enable
   **biometrics** (fingerprint / face).
3. On every later launch (within 7 days) it shows a **Lock screen** — unlock
   with biometrics or the PIN, no email/password. After 7 days, or "Mag-logout",
   a full login is required again.

Files: `services/session_service.dart`, `services/biometric_service.dart`,
`screens/auth_gate.dart`, `lock_screen.dart`, `setup_pin_screen.dart`,
`widgets/pin_pad.dart`. The session is stored locally only; for production you
may later add a server-issued refresh token.

### Platform setup for biometrics + secure storage (do once)
The zip ships only `lib/`, so generate the native folders first:
```
cd flutter_app
flutter create .          # creates android/ ios/ etc. without touching lib/
flutter pub get
```
Then:
- **Android** — `local_auth` needs a FragmentActivity. In
  `android/app/src/main/kotlin/.../MainActivity.kt` change
  `FlutterActivity` → `FlutterFragmentActivity`. In
  `android/app/src/main/AndroidManifest.xml` add inside `<manifest>`:
  `<uses-permission android:name="android.permission.USE_BIOMETRIC"/>`.
  `flutter_secure_storage` needs `minSdkVersion 18` (23+ recommended) in
  `android/app/build.gradle`.
- **iOS** — add to `ios/Runner/Info.plist`:
  `NSFaceIDUsageDescription = "Ginagamit para sa mabilis na pag-login."`

> On Chrome/web, biometrics and the Keychain aren't available; the app falls
> back to the PIN and to non-persistent login, which is fine for quick UI testing.

## Flow (unchanged from SOE)
1. **Request** (app) → `access_requests` (Pending) with ID + selfie.
2. **Review** in your **admin web** → Approved/Matched, token issued, linked to a `residents` row.
3. **Status** (app Track card) → reads the request status.
4. **Set password** (app) → `residents.Password`, `access_status='Active'`.
5. **Login** (app) → verifies against `residents`.
   **Forgot password** writes `residents.ResetToken`/`TokenExpiry` (email the link via your SMTP).

## ⚠️ Security — do this now
- Your `resident_forgot_password.php` has a **hard-coded Gmail SMTP app
  password** (and it is in the public Google Drive). **Revoke it** in your
  Google Account → Security → App passwords, generate a new one, and move it
  into a `.env` that is never uploaded/committed.
- `forgot_password.php` here only stores the reset token; wire the actual
  email send to your SMTP (see the TODO in `lib.php → request_password_reset`).
- API uses prepared statements, bcrypt passwords, one-time tokens; CORS is `*`
  for dev — tighten before production.
