# Password rules + Forgot password (email code) — Resident app

## Password rules
All password screens follow the same rules: **Set Password** (after
approval), **Settings → Change password**, and **Forgot password**.

- at least **8 characters** (max 72)
- an **uppercase** letter (A-Z)
- a **lowercase** letter (a-z)
- a **number** (0-9)
- a **special character** (! @ # ? * …)
- no spaces

As the resident types, a checklist ticks each rule and a bar shows
Mahina / Malapit na / Matibay. The server checks the same rules
(`password_policy_error()` in `user/backend/lib.php`), so a weak password is
rejected even from an old app.

## Forgot password — 6-digit code by email
The old flow saved a reset token but never sent an email (it was a TODO).
Now:

1. The resident enters their email and taps **Send Code**. The barangay
   Gmail sends a 6-digit code, valid for **15 minutes**.
2. In the app, the resident enters the code, the new password and the
   confirmation.
3. The password changes, and **every device is logged out**. The resident
   logs in with the new password.

The app uses a code instead of a web link because the phone can't open the
backend link when it's off the barangay Wi-Fi.

Safety:
- The screen never says whether an email is registered.
- One code per minute and 5 codes per hour.
- 5 wrong tries end the code.
- Codes are stored hashed and are one-time.
- The new password must differ from the old one.
- Only Active accounts can reset.

Data is kept in the new table `resident_password_resets`, created
automatically.

## Setting up the email (once)
It uses the **same Gmail** as the admin (access-request approval).
`user/backend/mailer.php` reads the first file it finds:

1. `CAPS/user/backend/private/smtp_config.php`
2. `CAPS/admin/residents/frontend/smtp_config.php` (the admin's)

If the admin's `smtp_config.php` already has a real **App Password**, there
is nothing else to do. If not:

1. Google Account → Security → turn on **2-Step Verification**.
2. Security → **App passwords** → create one (e.g. "Barangay Portal").
3. Copy `admin/residents/frontend/smtp_config.sample.php` to
   `smtp_config.php` and put the 16-character password in `'pass'`.

PHPMailer is already in `CAPS/vendor/`.

Test in PowerShell:

```
C:\xampp\php\php.exe C:\xampp\htdocs\CAPS\user\backend\mailer.php yourgmail@gmail.com
```

→ `Sent to ...`

When the email is not set up, the app says "Sending email is not set up
yet. Please visit or call the barangay hall." instead of pretending it
sent something.

**Never commit or share `smtp_config.php`.**
`CAPS/user/backend/private/*.php` is in `.gitignore`.

## Fix: "invalid email or password" right after Set Password

This was caused by the admin approving a request **linked to a profile the
admin had encoded** (`matched_resident_id`) that has **no email / contact
number**. Set Password saved the password on that profile, but the login
looks the resident up by email or contact number, so it never found the
account and said "invalid". Forgot Password couldn't find it either, so it
said "sent" without sending anything.

Now (`user/backend/lib.php`):
- Set Password links the request to the resident. If the profile has no
  email or contact number, it copies the ones from the request.
- Login and Forgot Password also find the account through the resident's
  approved request (`resident_accounts()`). Accounts already set up with the
  old code work without doing anything, and their email is saved on the
  first login.
- Duplicate or old records no longer block the login. Every matching record
  is checked, starting with the Active ones that have a password.

## Request access — date of birth
- A future date shows "Hindi puwedeng future date ang kapanganakan" right
  while typing. The calendar also stops at today.
- An impossible date (e.g. 02/30) shows "Gamitin ang mm/dd/yyyy".
- A year before 1900 has its own message.
- The server rejects a future date as well.

## Files
| File | |
|---|---|
| `user/backend/lib.php` | `password_policy_error()`, `request_password_reset()` (code), `reset_password_with_code()` |
| `user/backend/forgot_password.php` | `email` → send code; `action=reset` → new password |
| `user/backend/mailer.php` | *new* — PHPMailer + smtp_config.php, CLI test |
| `flutter_app/lib/widgets/password_rules.dart` | *new* — rules + live checklist |
| `flutter_app/lib/screens/forgot_password_screen.dart` | email → code + new password |
| `flutter_app/lib/screens/set_password_screen.dart`, `settings/change_password_screen.dart` | rules |
| `flutter_app/lib/screens/request_access_screen.dart` | birthdate messages |
