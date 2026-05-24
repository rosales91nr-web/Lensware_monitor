# sync_local.ps1 — Sincroniza CSV desde REPORTS vía PHP (tarea programada cada 1-5 min)
# Ejecutar en la PC con acceso a \\172.16.8.32\...

$PhpExe        = if ($env:LENSWARE_PHP) { $env:LENSWARE_PHP } else { "C:\xampp\php\php.exe" }
$MonitorScript = Join-Path $PSScriptRoot "monitor.php"
$LogFile       = Join-Path $PSScriptRoot "logs\sync_local.log"

if (-not (Test-Path $PhpExe)) {
    Write-Error "PHP no encontrado en: $PhpExe. Define LENSWARE_PHP o instala XAMPP."
    exit 1
}

$logDir = Split-Path $LogFile -Parent
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }

function Write-Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "[$ts] $msg" | Out-File -FilePath $LogFile -Append -Encoding UTF8
}

Write-Log "Iniciando sync..."
$output = & $PhpExe $MonitorScript 2>&1
$code   = $LASTEXITCODE

foreach ($line in ($output | Out-String).Trim().Split("`n")) {
    if ($line.Trim()) { Write-Log $line.Trim() }
}

if ($code -ne 0) {
    Write-Log "Sync falló (código $code)"
    exit $code
}

Write-Log "Sync OK"
exit 0
