# 2) Prepare XAMPP on this laptop: checks Apache/MySQL, opens the firewall
#    for the phone, imports the database, checks the Firebase files.
. "$PSScriptRoot\_common.ps1"
trap { Fail $_; Done; exit 1 }
Ensure-Admin $PSCommandPath

# ---- XAMPP -----------------------------------------------------------------
Step 'XAMPP'
if (-not (Test-Path $Php)) { Fail "Walang XAMPP sa $XamppRoot. I-install muna ang XAMPP."; Done; exit 1 }
Ok "XAMPP = $XamppRoot"
Ok "CAPS  = $CapsRoot"
if (-not $CapsRoot.StartsWith($Htdocs, [StringComparison]::OrdinalIgnoreCase)) {
    Warn "Wala ang CAPS sa loob ng $Htdocs. Ilipat ang CAPS folder doon."
}
if (Get-Process httpd -ErrorAction SilentlyContinue) { Ok 'Apache running' }
else { Warn 'Hindi naka-Start ang Apache. XAMPP Control Panel -> Apache -> Start' }
if (Get-Process mysqld -ErrorAction SilentlyContinue) { Ok 'MySQL running' }
else { Fail 'Hindi naka-Start ang MySQL. XAMPP Control Panel -> MySQL -> Start, tapos ulitin ito.'; Done; exit 1 }

# ---- PHP extensions --------------------------------------------------------
Step 'PHP extensions'
$mods = & $Php -m
foreach ($m in 'openssl', 'curl', 'pdo_mysql', 'mbstring') {
    if ($mods -contains $m) { Ok $m }
    else { Warn "$m naka-off. Buksan ang $XamppRoot\php\php.ini, alisin ang ; sa extension=$m, i-restart ang Apache." }
}

# ---- Firewall (so the phone can reach Apache) -------------------------------
Step 'Windows Firewall'
$httpd = Join-Path $XamppRoot 'apache\bin\httpd.exe'
if (Get-NetFirewallRule -DisplayName 'XAMPP Apache' -ErrorAction SilentlyContinue) { Ok 'May rule na' }
else {
    New-NetFirewallRule -DisplayName 'XAMPP Apache' -Direction Inbound -Program $httpd -Action Allow -Profile Private,Public | Out-Null
    Ok 'Pinayagan ang Apache (Private + Public)'
}

# ---- Database --------------------------------------------------------------
Step "Database ($DbName)"
$exists = & $Mysql -u root -N -e "SHOW DATABASES LIKE '$DbName'"
$db = Join-Path $CapsRoot 'database'
$backups = Get-ChildItem $db -Filter 'backup_*.sql' -ErrorAction SilentlyContinue | Sort-Object Name -Descending

function Import-Sql($file, $dbArg = '') {
    Write-Host "    Importing $(Split-Path $file -Leaf) ..."
    cmd /c "`"$Mysql`" -u root --default-character-set=utf8mb4 $dbArg < `"$file`""
    if ($LASTEXITCODE -ne 0) { throw "Import failed: $file" }
    Ok "Imported $(Split-Path $file -Leaf)"
}

$choice = ''
if ($exists) {
    Ok "May $DbName na sa laptop na ito."
    $choice = Read-Host '    I-import pa rin? [B]ackup galing sa lumang PC / [F]resh + sample / [Enter] = wag na'
} else {
    Warn "Wala pang $DbName."
    if ($backups) { $choice = Read-Host "    [B]ackup ($($backups[0].Name)) o [F]resh + sample residents? (B/F)" }
    else { $choice = 'F' }
}
switch -Regex ($choice) {
    '^[Bb]' {
        if (-not $backups) { Fail 'Walang backup_*.sql sa database folder. Gamitin ang option 6 sa lumang PC.' }
        else {
            & $Mysql -u root -e "CREATE DATABASE IF NOT EXISTS $DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            Import-Sql $backups[0].FullName $DbName
        }
    }
    '^[Ff]' {
        Import-Sql (Join-Path $db 'barangay_db_MERGED_SEPT27.sql')
        Import-Sql (Join-Path $db 'SAMPLE_RESIDENTS_SEPT27.sql')
    }
    default { Ok 'Walang binago sa database.' }
}

# ---- Firebase files (not in git; copy them with the CAPS folder) -----------
Step 'Firebase (push kahit sarado ang app)'
$gs  = Join-Path $FlutterApp 'android\app\google-services.json'
$key = Join-Path $Backend 'private\firebase_service_account.json'
if (Test-Path $gs)  { Ok 'google-services.json' } else { Warn "Wala: $gs (gagana ang app, pero walang push kapag sarado)" }
if (Test-Path $key) { Ok 'firebase_service_account.json' } else { Warn "Wala: $key (walang push)" }

# ---- Phone check -----------------------------------------------------------
Step 'Address para sa phone'
$ip = Get-LanIp
if (-not $ip) { Fail 'Walang Wi-Fi/LAN. Kumonekta muna sa Wi-Fi.'; Done; exit 1 }
$api = Get-ApiUrl $ip
Ok "IP ng laptop: $ip"
if (Test-Url "$api/officials.php") { Ok "Gumagana: $api/officials.php" }
else { Warn "Hindi mabuksan ang $api/officials.php - i-check ang Apache at database." }
Write-Host ''
Write-Host "Buksan sa Chrome ng phone (parehong Wi-Fi):  $api/officials.php" -ForegroundColor Green
Write-Host 'Dapat may {"success":true ...}' -ForegroundColor Green
Done
