# 1) Install everything needed to build the Android app (once per laptop):
#    Git, JDK 17, Flutter (if missing), Android SDK (no Android Studio).
. "$PSScriptRoot\_common.ps1"
trap { Fail $_; Done; exit 1 }
Ensure-Admin $PSCommandPath

Refresh-Env
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if (-not (Get-Command winget -ErrorAction SilentlyContinue)) {
    Fail 'Walang winget. I-update ang "App Installer" sa Microsoft Store, tapos ulitin.'
    Done; exit 1
}

# ---- Git (Flutter needs it) ------------------------------------------------
Step 'Git'
if (Get-Command git -ErrorAction SilentlyContinue) { Ok (git --version) }
else {
    winget install -e --id Git.Git --accept-source-agreements --accept-package-agreements
    Refresh-Env; Ok 'Git installed'
}

# ---- JDK 17 ----------------------------------------------------------------
Step 'Java JDK 17'
$jdk = Get-ChildItem 'C:\Program Files\Microsoft' -Directory -Filter 'jdk-17*' -ErrorAction SilentlyContinue |
    Sort-Object Name -Descending | Select-Object -First 1
if (-not $jdk) {
    winget install -e --id Microsoft.OpenJDK.17 --accept-source-agreements --accept-package-agreements
    $jdk = Get-ChildItem 'C:\Program Files\Microsoft' -Directory -Filter 'jdk-17*' |
        Sort-Object Name -Descending | Select-Object -First 1
}
if (-not $jdk) { Fail 'Hindi ma-install ang JDK 17.'; Done; exit 1 }
[Environment]::SetEnvironmentVariable('JAVA_HOME', $jdk.FullName, 'User')
$env:JAVA_HOME = $jdk.FullName
Add-UserPath "$($jdk.FullName)\bin"
Ok "JAVA_HOME = $($jdk.FullName)"

# ---- Flutter ---------------------------------------------------------------
Step 'Flutter SDK'
if (Get-Command flutter -ErrorAction SilentlyContinue) {
    Ok ('Nandiyan na: ' + (Get-Command flutter).Source)
} elseif (Test-Path "$FlutterDir\bin\flutter.bat") {
    Add-UserPath "$FlutterDir\bin"; Ok "Nandiyan na: $FlutterDir"
} else {
    $base = 'https://storage.googleapis.com/flutter_infra_release/releases'
    $rel  = Invoke-RestMethod "$base/releases_windows.json"
    $hash = $rel.current_release.stable
    $r    = $rel.releases | Where-Object { $_.hash -eq $hash } | Select-Object -First 1
    $zip  = Join-Path $env:TEMP 'flutter_sdk.zip'
    Write-Host "    Downloading Flutter $($r.version) (~1 GB, matagal ito)..."
    $ProgressPreference = 'SilentlyContinue'
    Invoke-WebRequest "$base/$($r.archive)" -OutFile $zip -UseBasicParsing
    Write-Host '    Extracting to C:\ ...'
    tar -xf $zip -C 'C:\'
    Remove-Item $zip -Force
    Add-UserPath "$FlutterDir\bin"
    Ok "Flutter $($r.version) -> $FlutterDir"
}

# ---- Android SDK (command-line tools only) ---------------------------------
Step 'Android SDK'
$sdkmanager = "$AndroidSdk\cmdline-tools\latest\bin\sdkmanager.bat"
if (-not (Test-Path $sdkmanager)) {
    $zip = Join-Path $env:TEMP 'cmdline-tools.zip'
    $tmp = Join-Path $env:TEMP 'cmdline-tools-x'
    Write-Host '    Downloading Android command-line tools...'
    $ProgressPreference = 'SilentlyContinue'
    Invoke-WebRequest 'https://dl.google.com/android/repository/commandlinetools-win-13114758_latest.zip' -OutFile $zip -UseBasicParsing
    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
    Expand-Archive $zip $tmp -Force
    New-Item -ItemType Directory -Force "$AndroidSdk\cmdline-tools" | Out-Null
    if (Test-Path "$AndroidSdk\cmdline-tools\latest") { Remove-Item "$AndroidSdk\cmdline-tools\latest" -Recurse -Force }
    Move-Item "$tmp\cmdline-tools" "$AndroidSdk\cmdline-tools\latest"
    Remove-Item $zip, $tmp -Recurse -Force -ErrorAction SilentlyContinue
}
[Environment]::SetEnvironmentVariable('ANDROID_HOME', $AndroidSdk, 'User')
[Environment]::SetEnvironmentVariable('ANDROID_SDK_ROOT', $AndroidSdk, 'User')
$env:ANDROID_HOME = $AndroidSdk; $env:ANDROID_SDK_ROOT = $AndroidSdk
Add-UserPath "$AndroidSdk\cmdline-tools\latest\bin"
Add-UserPath "$AndroidSdk\platform-tools"

Write-Host '    Accepting licenses + installing platform-tools, Android 36, build-tools...'
$yes = ('y' + [Environment]::NewLine) * 30
$yes | & $sdkmanager --licenses | Out-Null
& $sdkmanager 'platform-tools' 'platforms;android-36' 'build-tools;36.0.0'
Ok "Android SDK = $AndroidSdk"

# ---- Flutter <-> Android ---------------------------------------------------
Step 'flutter config + doctor'
Refresh-Env
flutter config --android-sdk $AndroidSdk | Out-Null
$yes | flutter doctor --android-licenses | Out-Null
flutter doctor

Write-Host ''
Write-Host 'Tapos! OK lang kung may X sa "Visual Studio" (pang-Windows app lang iyon).' -ForegroundColor Green
Write-Host 'Isara at buksan ulit ang PowerShell/VS Code para gumana ang bagong PATH.' -ForegroundColor Green
Done
