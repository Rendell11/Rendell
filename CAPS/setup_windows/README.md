# Setup sa ibang laptop (Windows) — Resident app

I-double-click ang **`SETUP.bat`** sa folder na ito, tapos pumili ng number.
Kapag kailangan ng Administrator, may lalabas na "Yes/No" — piliin ang **Yes**.

| # | Ano | Kailan |
|---|---|---|
| 1 | Install tools: Git, JDK 17, Flutter, Android SDK (walang Android Studio) | isang beses lang, bagong laptop |
| 2 | Setup server: XAMPP check, firewall para sa phone, import database, check Firebase files, IP ng laptop | isang beses lang, bagong laptop |
| 3 | Run sa phone gamit ang USB (hot reload) | tuwing magte-test |
| 4 | Build APK → nasa Desktop, `Binang2nd-Resident-<ip>.apk` | pang-install sa ibang phone |
| 5 | Push scheduler: pinapatakbo ang `push_worker.php` every 1 minute sa background | isang beses lang, kung may Firebase |
| 6 | Backup ng database → `CAPS\database\backup_<date>.sql` | sa **lumang** PC, bago ilipat |

Awtomatikong kinukuha ang IP ng laptop, kaya hindi na kailangang i-type.

## Paglipat sa ibang laptop

**Sa PC na ito (luma):**
1. XAMPP → Start ang MySQL → `SETUP.bat` → **6** (backup database).
2. Kopyahin ang **buong `CAPS` folder** (USB/flash drive). Kasama dapat ang
   mga file na wala sa git:
   - `flutter_app\android\app\google-services.json`
   - `user\backend\private\firebase_service_account.json`
   - `user\backend\uploads\` (mga ID, selfie, attachment)
   - `database\backup_*.sql`
   - `admin\db.php` at ang iba pang admin files

   Para mas mabilis, huwag nang kopyahin ang `flutter_app\build\` at
   `flutter_app\.dart_tool\` dahil gagawin ulit ang mga iyan.

**Sa bagong laptop:**
1. I-install ang **XAMPP** (default `C:\xampp`) at i-Start ang Apache at MySQL.
2. I-paste ang `CAPS` sa `C:\xampp\htdocs\CAPS`.
3. `SETUP.bat` → **1**. Mga 20–40 minutes ito dahil malaki ang Flutter at
   Android SDK. Pagkatapos, isara at buksan ulit ang `SETUP.bat`.
4. `SETUP.bat` → **2** → piliin ang **[B]ackup** (para dala ang data) o
   **[F]resh** (bagong database + 30 sample residents).
5. Phone: parehong Wi-Fi, USB mode = **Transferring files**, USB debugging
   ON → `SETUP.bat` → **3**.
6. (Push) `SETUP.bat` → **5**.

## Mahalaga

- **Iba ang IP ng bawat laptop o Wi-Fi.** Ang APK na na-build sa lumang PC
  ay nakaturo sa lumang IP. Sa bagong laptop, i-run ulit ang **3** o **4**.
  Kung mag-iba ang IP (ibang Wi-Fi), mag-rebuild lang din.
- Ang `firebase_service_account.json` ay parang password. Huwag i-upload,
  huwag i-send sa chat, at huwag i-commit. Sa flash drive lang ilipat.
- Hindi na kailangang gumawa ulit ng Firebase project. Parehong files
  lang ang gamit, kahit anong laptop.
- Kung may password ang MySQL root (hindi default ng XAMPP), i-update ang
  `admin\db.php` o `user\backend\config.php`.
- Kung ayaw gumana ng isang option, i-screenshot ang pulang `[X]` o ang
  "What went wrong".

## Manual (kung ayaw mo ng menu)

```powershell
cd C:\xampp\htdocs\CAPS\flutter_app
flutter pub get
flutter run -d <device-id> --dart-define=API_BASE_URL=http://<ip-ng-laptop>/CAPS/user/backend
flutter build apk --release --dart-define=API_BASE_URL=http://<ip-ng-laptop>/CAPS/user/backend
C:\xampp\php\php.exe C:\xampp\htdocs\CAPS\user\backend\push_worker.php
```

Alamin ang IP gamit ang `ipconfig` → IPv4 Address ng Wi-Fi. Makikita ang
device-id sa `flutter devices`.
