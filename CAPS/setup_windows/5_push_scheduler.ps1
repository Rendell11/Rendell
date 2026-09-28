# 5) Send push notifications automatically: runs user/backend/push_worker.php
#    every minute in the background (Windows Task Scheduler, no window).
#    Remove it with:  5_push_scheduler.ps1 -Remove
param([switch]$Remove)
. "$PSScriptRoot\_common.ps1"
trap { Fail $_; Done; exit 1 }
Ensure-Admin $PSCommandPath ($(if ($Remove) { '-Remove' } else { '' }))

$TaskName = 'Binang2nd Resident Push'
$worker = Join-Path $Backend 'push_worker.php'

if ($Remove) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Ok "Tinanggal ang task '$TaskName'"
    Done; exit 0
}

Step 'Check'
if (-not (Test-Path $Php)) { Fail "Walang $Php"; Done; exit 1 }
if (-not (Test-Path (Join-Path $Backend 'private\firebase_service_account.json'))) {
    Fail 'Walang user\backend\private\firebase_service_account.json (Firebase -> Service accounts -> Generate new private key).'
    Done; exit 1
}
Ok $worker

Step 'Test run'
& $Php $worker

Step "Task Scheduler: '$TaskName' (every 1 minute)"
$action    = New-ScheduledTaskAction -Execute $Php -Argument "`"$worker`"" -WorkingDirectory $Backend
$trigger   = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings  = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal `
    -Settings $settings -Force | Out-Null
Ok 'Naka-schedule na. Gagana habang naka-Start ang Apache/MySQL sa XAMPP.'
Done
