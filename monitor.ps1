# monitor.ps1 - Sube el CSV más reciente a Railway cada N segundos
# Ejecutar en el servidor Windows con acceso a \\172.16.8.32\...
# La clave debe coincidir con UPLOAD_SECRET en Railway Variables

param(
    [string]$UploadSecret = $env:LENSWARE_UPLOAD_SECRET,
    [string]$CsvFolder     = "\\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS",
    [string]$RailwayUrl    = "https://lenswaremonitor-production.up.railway.app",
    [string]$LogFile       = "C:\csv_upload_log.txt",
    [string]$StateFile     = "C:\csv_upload_state.json",
    [int]$IntervalSeconds = 10
)

if (-not $UploadSecret) {
    Write-Host "ERROR: Define LENSWARE_UPLOAD_SECRET o pasa -UploadSecret" -ForegroundColor Red
    exit 1
}

$UploadUri = "$RailwayUrl/api.php?action=upload_csv"
$Prefixes  = @("UNI_PROD_ALL_ACT_", "UNI_PROD_SIMPLE_ACT_")

function Write-Log($msg) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$ts] $msg"
    $line | Out-File -FilePath $LogFile -Append -Encoding UTF8
    Write-Host $line
}

function Get-State {
    if (Test-Path $StateFile) {
        try { return Get-Content $StateFile -Raw | ConvertFrom-Json } catch { }
    }
    return [PSCustomObject]@{ file = ""; mtime = 0 }
}

function Set-State($file, $mtime) {
    @{ file = $file; mtime = $mtime } | ConvertTo-Json | Set-Content $StateFile -Encoding UTF8
}

function Get-LatestCsv {
    $latest = $null
    foreach ($prefix in $Prefixes) {
        $found = Get-ChildItem $CsvFolder -Filter "${prefix}*.csv" -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1
        if ($found -and (-not $latest -or $found.LastWriteTime -gt $latest.LastWriteTime)) {
            $latest = $found
        }
    }
    return $latest
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host " MONITOR CSV -> Railway (Lensware)" -ForegroundColor Green
Write-Host " Carpeta: $CsvFolder" -ForegroundColor Yellow
Write-Host " Destino: $UploadUri" -ForegroundColor Yellow
Write-Host " Intervalo: ${IntervalSeconds}s" -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Cyan

while ($true) {
    $latest = Get-LatestCsv

    if ($latest) {
        $mtimeTicks = $latest.LastWriteTimeUtc.Ticks
        $state      = Get-State
        $changed    = ($latest.Name -ne $state.file) -or ($mtimeTicks -ne $state.mtime)

        if ($changed) {
            Write-Log "Nuevo/actualizado: $($latest.Name) ($([math]::Round($latest.Length/1KB,1)) KB)"
            try {
                $form = @{
                    csv_file = $latest
                    secret   = $UploadSecret
                }
                $response = Invoke-RestMethod `
                    -Uri $UploadUri `
                    -Method POST `
                    -Form $form `
                    -Headers @{ "X-Upload-Secret" = $UploadSecret } `
                    -TimeoutSec 120

                if ($response.success) {
                    $rec = if ($response.records) { " ($($response.records) registros)" } else { "" }
                    Write-Log "OK: $($response.message)$rec"
                    Set-State $latest.Name $mtimeTicks
                } else {
                    Write-Log "ERROR servidor: $($response.error)"
                }
            } catch {
                Write-Log "EXCEPCION: $_"
            }
        }
    }

    Start-Sleep -Seconds $IntervalSeconds
}
