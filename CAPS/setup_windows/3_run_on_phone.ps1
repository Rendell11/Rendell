# 3) Run the app on the phone connected by USB (debug, with hot reload).
#    The laptop's current IP is used automatically.
param([string]$Ip = '')
. "$PSScriptRoot\_common.ps1"
trap { Fail $_; Done; exit 1 }
Require-Flutter

if (-not $Ip) { $Ip = Get-LanIp }
if (-not $Ip) { Fail 'Walang Wi-Fi/LAN. Kumonekta muna sa Wi-Fi.'; Done; exit 1 }
$api = Get-ApiUrl $Ip
Step "Backend: $api"
if (Test-Url "$api/officials.php") { Ok 'Gumagana ang backend' }
else { Warn 'Hindi mabuksan ang backend. I-Start ang Apache/MySQL o i-run ang option 2.' }

Step 'Phone'
$raw = (flutter devices --machine | Out-String)
$json = $raw.Substring([Math]::Max(0, $raw.IndexOf('[')))
$devs = @($json | ConvertFrom-Json | Where-Object { $_.targetPlatform -like 'android*' })
if ($devs.Count -eq 0) {
    Fail 'Walang nakitang Android phone.'
    Write-Host '    - USB cable na pang-data, USB mode = "Transferring files" (hindi tethering)'
    Write-Host '    - Developer options -> USB debugging ON, tapos "Allow" sa prompt ng phone'
    Done; exit 1
}
$dev = $devs[0]
if ($devs.Count -gt 1) {
    for ($i = 0; $i -lt $devs.Count; $i++) { Write-Host "    [$i] $($devs[$i].name) ($($devs[$i].id))" }
    $dev = $devs[[int](Read-Host '    Piliin ang number')]
}
Ok "$($dev.name) ($($dev.id))"

Set-Location $FlutterApp
Step 'flutter pub get'
flutter pub get
Step 'flutter run  (r = reload, R = restart, q = quit)'
Write-Host '    Unang build: 10-20 minutes. Kapag nagtanong ang phone "Install via USB?" -> Install.'
flutter run -d $dev.id "--dart-define=API_BASE_URL=$api"
Done
