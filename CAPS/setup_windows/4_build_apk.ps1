# 4) Build an installable APK (share it by Messenger / USB / Drive).
#    The APK only talks to the IP it was built with: rebuild when the
#    laptop or Wi-Fi changes.
param([string]$Ip = '')
. "$PSScriptRoot\_common.ps1"
trap { Fail $_; Done; exit 1 }
Require-Flutter

if (-not $Ip) { $Ip = Get-LanIp }
if (-not $Ip) { Fail 'Walang Wi-Fi/LAN. Kumonekta muna sa Wi-Fi.'; Done; exit 1 }
$api = Get-ApiUrl $Ip
Step "Backend na nakabaon sa APK: $api"
if (-not (Test-Path (Join-Path $FlutterApp 'android\app\google-services.json'))) {
    Warn 'Walang google-services.json: walang push kapag sarado ang app.'
}

Set-Location $FlutterApp
Step 'flutter pub get'
flutter pub get
Step 'flutter build apk --release'
flutter build apk --release "--dart-define=API_BASE_URL=$api"
if ($LASTEXITCODE -ne 0) { Fail 'Build failed. I-screenshot ang "What went wrong".'; Done; exit 1 }

$apk = Join-Path $FlutterApp 'build\app\outputs\flutter-apk\app-release.apk'
$out = Join-Path ([Environment]::GetFolderPath('Desktop')) "Binang2nd-Resident-$($Ip.Replace('.', '-')).apk"
Copy-Item $apk $out -Force
Ok "APK: $out"
Write-Host ''
Write-Host 'I-send sa phone at i-install (payagan ang "Install unknown apps").' -ForegroundColor Green
Write-Host "Gagana lang habang naka-on ang laptop na ito sa IP $Ip at parehong Wi-Fi." -ForegroundColor Green
Done
