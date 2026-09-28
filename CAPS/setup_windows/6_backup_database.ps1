# 6) Save the current database to CAPS\database\backup_<date>.sql so it can
#    be carried to another laptop together with the CAPS folder.
. "$PSScriptRoot\_common.ps1"
trap { Fail $_; Done; exit 1 }

if (-not (Get-Process mysqld -ErrorAction SilentlyContinue)) {
    Fail 'Hindi naka-Start ang MySQL (XAMPP Control Panel -> MySQL -> Start).'; Done; exit 1
}
$out = Join-Path $CapsRoot ("database\backup_{0}.sql" -f (Get-Date -Format 'yyyyMMdd_HHmm'))
Step "Backup $DbName"
cmd /c "`"$MysqlDump`" -u root --default-character-set=utf8mb4 --routines --triggers --single-transaction $DbName > `"$out`""
if ($LASTEXITCODE -ne 0) { Fail 'Backup failed.'; Done; exit 1 }
Ok $out
Write-Host ''
Write-Host 'Sa bagong laptop: option 2 -> piliin ang [B]ackup.' -ForegroundColor Green
Done
