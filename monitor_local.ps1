# monitor_local.ps1 — Bucle continuo: sincroniza REPORTS ? caché PHP cada N segundos
# Ejecutar en consola o como tarea al iniciar Windows

param(
    [string]$PhpExe           = $(if ($env:LENSWARE_PHP) { $env:LENSWARE_PHP } else { "C:\xampp\php\php.exe" }),
    [string]$ReportsFolder    = "\\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS",
    [int]$IntervalSeconds     = 30,
    [string]$LogFile          = ""
)

$MonitorScript = Join-Path $PSScriptRoot "monitor.php"
if ($LogFile -eq "") { $LogFile = Join-Path $PSScriptRoot "logs\monitor_local.log" }

$logDir = Split-Path $LogFile -Parent
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }

function Write-Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$ts] $msg"
    $line | Out-File -FilePath $LogFile -Append -Encoding UTF8
    Write-Host $line
}

if (-not (Test-Path $PhpExe)) {
    Write-Host "ERROR: PHP no encontrado: $PhpExe" -ForegroundColor Red
    exit 1
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host " Lensware Pro — Monitor local (XAMPP)" -ForegroundColor Green
Write-Host " REPORTS: $ReportsFolder" -ForegroundColor Yellow
Write-Host " PHP:     $PhpExe" -ForegroundColor Yellow
Write-Host " Intervalo: ${IntervalSeconds}s" -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Cyan

while ($true) {
    if (-not (Test-Path $ReportsFolder)) {
        Write-Log "REPORTS no accesible: $ReportsFolder"
    } else {
        $output = & $PhpExe $MonitorScript 2>&1
        $ok = ($LASTEXITCODE -eq 0)
        $summary = ($output | Select-Object -Last 1) -join " "
        if ($ok) {
            Write-Log "OK — $summary"
        } else {
            Write-Log "ERROR — $summary"
            ($output | Out-String).Trim().Split("`n") | ForEach-Object {
                if ($_.Trim()) { Write-Log "  $_" }
            }
        }
    }
    Start-Sleep -Seconds $IntervalSeconds
}
