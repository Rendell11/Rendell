# Shared helpers for the setup scripts (dot-sourced; not run directly).
# ASCII only: Windows PowerShell 5.1 reads BOM-less scripts as ANSI.

$ErrorActionPreference = 'Stop'

$CapsRoot   = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$FlutterApp = Join-Path $CapsRoot 'flutter_app'
$Backend    = Join-Path $CapsRoot 'user\backend'

# XAMPP: the folder that holds htdocs\CAPS, else C:\xampp
$XamppRoot = 'C:\xampp'
$htdocsParent = Split-Path (Split-Path $CapsRoot -Parent) -Parent
if ((Split-Path (Split-Path $CapsRoot -Parent) -Leaf) -eq 'htdocs' -and (Test-Path (Join-Path $htdocsParent 'php\php.exe'))) {
    $XamppRoot = $htdocsParent
}
$Php   = Join-Path $XamppRoot 'php\php.exe'
$Mysql = Join-Path $XamppRoot 'mysql\bin\mysql.exe'
$MysqlDump = Join-Path $XamppRoot 'mysql\bin\mysqldump.exe'
$Htdocs = Join-Path $XamppRoot 'htdocs'

$DbName = 'barangay_db'
$AndroidSdk = 'C:\Android'
$FlutterDir = 'C:\flutter'

function Step($text)  { Write-Host ''; Write-Host "==> $text" -ForegroundColor Cyan }
function Ok($text)    { Write-Host "    [OK] $text" -ForegroundColor Green }
function Warn($text)  { Write-Host "    [!]  $text" -ForegroundColor Yellow }
function Fail($text)  { Write-Host "    [X]  $text" -ForegroundColor Red }

function Test-Admin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# Re-open the calling script as Administrator (UAC prompt) and stop this copy.
function Ensure-Admin([string]$scriptPath, [string]$extraArgs = '') {
    if (Test-Admin) { return }
    Write-Host 'Kailangan ng Administrator. May lalabas na "Yes/No" - piliin ang Yes.' -ForegroundColor Yellow
    Start-Process powershell.exe -Verb RunAs -ArgumentList "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`" $extraArgs"
    exit
}

# Reload PATH / env vars that an installer just changed.
function Refresh-Env {
    $machine = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $user    = [Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = "$machine;$user"
    foreach ($n in 'JAVA_HOME', 'ANDROID_HOME', 'ANDROID_SDK_ROOT') {
        $v = [Environment]::GetEnvironmentVariable($n, 'User')
        if (-not $v) { $v = [Environment]::GetEnvironmentVariable($n, 'Machine') }
        if ($v) { Set-Item "env:$n" $v }
    }
}

# Add a folder to the user's PATH (permanent) and to this session.
function Add-UserPath([string]$dir) {
    $cur = [Environment]::GetEnvironmentVariable('Path', 'User')
    $parts = @()
    if ($cur) { $parts = $cur.Split(';') | Where-Object { $_ } }
    if ($parts -notcontains $dir) {
        [Environment]::SetEnvironmentVariable('Path', (($parts + $dir) -join ';'), 'User')
    }
    if (($env:Path.Split(';')) -notcontains $dir) { $env:Path = "$env:Path;$dir" }
}

# The PC's LAN IPv4 (the adapter with a default gateway; Wi-Fi first).
function Get-LanIp {
    $cfg = Get-NetIPConfiguration -ErrorAction SilentlyContinue |
        Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' -and $_.IPv4Address }
    $wifi = $cfg | Where-Object { $_.InterfaceAlias -match 'Wi-?Fi|Wireless|WLAN' } | Select-Object -First 1
    $pick = if ($wifi) { $wifi } else { $cfg | Select-Object -First 1 }
    if ($pick) { return ($pick.IPv4Address | Select-Object -First 1).IPAddress }
    return $null
}

# http://<ip>/CAPS/user/backend (follows where CAPS sits inside htdocs)
function Get-ApiUrl([string]$ip) {
    $rel = 'CAPS'
    if ($CapsRoot.StartsWith($Htdocs, [StringComparison]::OrdinalIgnoreCase)) {
        $rel = $CapsRoot.Substring($Htdocs.Length).Trim('\').Replace('\', '/')
    }
    return "http://$ip/$rel/user/backend"
}

function Test-Url([string]$url) {
    try {
        $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 6
        return ($r.StatusCode -eq 200 -and $r.Content -match '"success"')
    } catch { return $false }
}

function Require-Flutter {
    Refresh-Env
    if (-not (Get-Command flutter -ErrorAction SilentlyContinue)) {
        if (Test-Path "$FlutterDir\bin\flutter.bat") { Add-UserPath "$FlutterDir\bin" }
        else { Fail 'Walang Flutter. I-run muna ang option 1 (Install tools).'; Done; exit 1 }
    }
}

function Done {
    Write-Host ''
    Read-Host 'Pindutin ang Enter para isara' | Out-Null
}
