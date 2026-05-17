# push_csv.ps1 - Subida única del CSV más reciente (tarea programada cada 5 min)
# Requisito: PowerShell 5+

$RailwayUrl   = "https://lenswaremonitor-production.up.railway.app/api.php?action=upload_csv"
$UploadSecret = $env:LENSWARE_UPLOAD_SECRET   # igual que UPLOAD_SECRET en Railway
$WatchFolder  = "\\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS"
$Prefixes     = @("UNI_PROD_ALL_ACT_", "UNI_PROD_SIMPLE_ACT_")
$LogFile      = "$PSScriptRoot\push_csv.log"

if (-not $UploadSecret) {
    Write-Error "Define la variable de entorno LENSWARE_UPLOAD_SECRET"
    exit 1
}

function Write-Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "[$ts] $msg" | Out-File -FilePath $LogFile -Append -Encoding UTF8
}

Write-Log "Buscando CSV en $WatchFolder"

$latest = $null
foreach ($prefix in $Prefixes) {
    $files = Get-ChildItem -Path $WatchFolder -Filter "${prefix}*.csv" -ErrorAction SilentlyContinue |
             Sort-Object LastWriteTime -Descending |
             Select-Object -First 1
    if ($files -and (!$latest -or $files.LastWriteTime -gt $latest.LastWriteTime)) {
        $latest = $files
    }
}

if (-not $latest) {
    Write-Log "No se encontró ningún CSV"
    exit 1
}

Write-Log "Subiendo: $($latest.Name)"

try {
    $response = Invoke-RestMethod `
        -Uri $RailwayUrl `
        -Method POST `
        -Form @{ csv_file = $latest; secret = $UploadSecret } `
        -Headers @{ "X-Upload-Secret" = $UploadSecret } `
        -TimeoutSec 120

    if ($response.success) {
        Write-Log "OK: $($response.message) registros=$($response.records)"
    } else {
        Write-Log "ERROR: $($response.error)"
        exit 1
    }
} catch {
    Write-Log "EXCEPCION: $_"
    exit 1
}
