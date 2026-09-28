# Android app + push notifications (pop-up kahit sarado ang app)

The resident app now has an `android/` folder (`flutter_app/android/`,
package **`ph.gov.binang2nd.resident_access`**, Android 7.0+). With Firebase
set up, notifications pop up even when the app is closed, and disaster alerts
use the sound of their severity.

| Push | Android channel | Sound |
|---|---|---|
| Document / complaint / blotter updates, announcements, hearing reminder | `barangay_updates` | phone's normal notification sound |
| Disaster alert · Low | `alert_low` | soft chime |
| Disaster alert · Medium | `alert_medium` | two double beeps |
| Disaster alert · High | `alert_high` | urgent triple beeps (pops up on top) |
| Disaster alert · Critical | `alert_critical` | **siren — rings even in silent mode** |
| Disaster alert, resident turned *Alert sound* off | `alert_silent` | no sound |

Only alerts issued with **App Notification** ticked (Announcements → Issue
Alert) are pushed. The same sounds play inside the app while it is open.

## 1. Once, on your PC

1. Install **Android Studio** (it installs the Android SDK). Run
   `flutter doctor` until "Android toolchain" is ✓.
2. In `flutter_app/`: `flutter pub get`.

## 2. Firebase (free)

1. <https://console.firebase.google.com> → **Add project** (e.g. `binang2nd-resident`).
2. In the project → **Add app → Android**:
   - Android package name: `ph.gov.binang2nd.resident_access`
   - Download **`google-services.json`** → put it in
     **`flutter_app/android/app/google-services.json`**.
   (Without this file the app still builds; push is just off.)
3. **Project settings → Service accounts → Generate new private key** →
   save it as **`CAPS/user/backend/private/firebase_service_account.json`**
   (the `private/` folder is blocked from the web — never put the key
   anywhere else and never share it).
4. **Windows Task Scheduler** → Create Basic Task → repeat **every 1 minute**:
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\CAPS\user\backend\push_worker.php`

   Test it once in a terminal: `C:\xampp\php\php.exe C:\xampp\htdocs\CAPS\user\backend\push_worker.php`
   → `... sent to N device(s)`.

## 3. Run / build the app on a phone

The phone reaches XAMPP over Wi-Fi:

1. PC and phone on the **same Wi-Fi**. On the PC run `ipconfig` → IPv4,
   e.g. `192.168.1.10`.
2. Allow Apache through **Windows Firewall** (Private networks).
3. Check from the phone's browser: `http://192.168.1.10/CAPS/user/backend/officials.php`
   must show JSON.
4. Phone connected by USB (Developer options → USB debugging):

   ```
   flutter run --dart-define=API_BASE_URL=http://192.168.1.10/CAPS/user/backend
   ```

   Or build an APK to share:

   ```
   flutter build apk --release --dart-define=API_BASE_URL=http://192.168.1.10/CAPS/user/backend
   ```

   → `flutter_app/build/app/outputs/flutter-apk/app-release.apk`

## 4. Test

1. Log in on the phone → **Allow** notifications.
2. Close the app (swipe it away).
3. Admin → Announcements → **Issue Alert**, tick **App Notification**,
   severity **Critical** → within about a minute the phone shows the alert
   with the siren.
4. Settings → Notifications → turn **Alert sound** off → issue another alert
   → it shows without sound.

## Good to know

- Some phones (Xiaomi, Oppo, Vivo, Realme…) delay notifications of closed
  apps to save battery: Settings → Apps → Biñang 2nd Resident → Battery →
  **No restrictions** / allow **Autostart**.
- Android keeps a channel's sound once created. If the sounds are changed
  later, uninstall and reinstall the app.
- `android:usesCleartextTraffic="true"` is on because XAMPP is plain `http`.
  When the backend moves to `https`, it can be removed.
- The app still signs with the debug key (`flutter build apk` works). For
  the Play Store, add your own release key (see flutter.dev/to/reference-keystore).
