# push_csv.ps1 - Ejecutar en el servidor Windows Lensware
# Tarea programada: cada 5 minutos
# Requisito: PowerShell 5+

# ---- Configura estas variables ----
$RailwayUrl   = "https://TU-APP.up.railway.app/api.php?action=upload_csv"
$UploadSecret = "TU_UPLOAD_SECRET"   # igual que la variable UPLOAD_SECRET en Railway
$WatchFolder  = "\\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS"
$Prefixes     = @("UNI_PROD_ALL_ACT_", "UNI_PROD_SIMPLE_ACT_")
$LogFile      = "$PSScriptRoot\push_csv.log"
# -----------------------------------

function Write-Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "[$ts] $msg" | Out-File -FilePath $LogFile -Append -Encoding UTF8
}

Write-Log "Iniciando búsqueda de CSV en $WatchFolder"

# Encontrar el CSV más reciente con los prefijos válidos
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
    Write-Log "ERROR: No se encontró ningún CSV"
    exit 1
}

Write-Log "Encontrado: $($latest.Name) ($([math]::Round($latest.Length/1KB,1)) KB)"

# Subir al servidor Railway
try {
    $form = @{
        csv_file = Get-Item $latest.FullName
        secret   = $UploadSecret
    }

    $response = Invoke-RestMethod `
        -Uri $RailwayUrl `
        -Method POST `
        -Form $form `
        -Headers @{ "X-Upload-Secret" = $UploadSecret } `
        -TimeoutSec 60

    if ($response.success) {
        Write-Log "OK: $($response.message)"
    } else {
        Write-Log "ERROR del servidor: $($response.error)"
    }
} catch {
    Write-Log "EXCEPCION: $_"
    exit 1
}
