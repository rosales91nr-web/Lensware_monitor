# push_to_replit.ps1
# Sube automáticamente el CSV más reciente de Lensware a Replit.
# Configurar REPLIT_URL y UPLOAD_SECRET abajo (o como variables de entorno).
#
# INSTRUCCIONES:
#   1. Edita las variables de configuración en la sección "CONFIG" abajo.
#   2. Ejecuta este script manualmente o programa con el Programador de tareas
#      de Windows para correr cada 1-5 minutos.
#
# Programador de tareas (como admin):
#   Acción:  powershell.exe
#   Argumentos: -ExecutionPolicy Bypass -File "C:\ruta\push_to_replit.ps1"
#   Inicio:  C:\ruta\

# ─── CONFIG ──────────────────────────────────────────────────────────────────
# URL del dashboard en Replit (reemplaza con tu URL real de .replit.app)
$REPLIT_URL    = $env:REPLIT_URL    ?: "https://TU-APP.replit.app/api.php"

# Token de seguridad — debe coincidir con el secreto UPLOAD_SECRET en Replit
$UPLOAD_SECRET = $env:UPLOAD_SECRET ?: "CAMBIA_ESTE_TOKEN"

# Carpeta donde Lensware genera los CSV
$REPORTS_FOLDER = $env:REPORTS_FOLDER ?: "\\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS"

# Prefijos válidos de archivos CSV de Lensware
$CSV_PREFIXES = @("UNI_PROD_ALL_ACT_", "UNI_PROD_SIMPLE_ACT_")

# Tamaño mínimo en bytes para considerar un CSV válido (evita archivos vacíos)
$MIN_SIZE_BYTES = 100

# ─── FIN CONFIG ───────────────────────────────────────────────────────────────

function Write-Log {
    param([string]$Msg, [string]$Level = "INFO")
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Write-Host "[$ts] [$Level] $Msg"
}

Write-Log "=== Push Lensware → Replit ==="

# Verificar que la carpeta de reportes sea accesible
if (-not (Test-Path $REPORTS_FOLDER)) {
    Write-Log "No se puede acceder a: $REPORTS_FOLDER" "ERROR"
    exit 1
}

# Buscar el CSV más reciente con prefijo válido
$latest = $null
foreach ($prefix in $CSV_PREFIXES) {
    $files = Get-ChildItem -Path $REPORTS_FOLDER -Filter "$prefix*.csv" -File -ErrorAction SilentlyContinue |
             Where-Object { $_.Length -ge $MIN_SIZE_BYTES } |
             Sort-Object LastWriteTime -Descending |
             Select-Object -First 1
    if ($files -and (-not $latest -or $files.LastWriteTime -gt $latest.LastWriteTime)) {
        $latest = $files
    }
}

if (-not $latest) {
    Write-Log "No se encontró ningún CSV válido en: $REPORTS_FOLDER" "WARN"
    exit 0
}

Write-Log "Archivo encontrado: $($latest.Name) ($([math]::Round($latest.Length/1024,1)) KB)"

# ─── Upload ───────────────────────────────────────────────────────────────────
$uploadUrl = "$REPLIT_URL`?action=upload_csv"

try {
    # Leer el archivo CSV
    $fileBytes = [System.IO.File]::ReadAllBytes($latest.FullName)

    # Construir multipart/form-data manualmente
    $boundary = "----LensPush" + [System.Guid]::NewGuid().ToString("N")
    $CRLF = "`r`n"

    $bodyParts = [System.Collections.Generic.List[byte]]::new()

    # Campo csv_file
    $header = "--$boundary${CRLF}Content-Disposition: form-data; name=`"csv_file`"; filename=`"$($latest.Name)`"${CRLF}Content-Type: text/csv${CRLF}${CRLF}"
    $bodyParts.AddRange([System.Text.Encoding]::UTF8.GetBytes($header))
    $bodyParts.AddRange($fileBytes)
    $bodyParts.AddRange([System.Text.Encoding]::UTF8.GetBytes($CRLF))

    # Cierre
    $bodyParts.AddRange([System.Text.Encoding]::UTF8.GetBytes("--$boundary--$CRLF"))

    $headers = @{
        "Content-Type"    = "multipart/form-data; boundary=$boundary"
        "X-Upload-Secret" = $UPLOAD_SECRET
    }

    Write-Log "Subiendo a: $uploadUrl"
    $response = Invoke-RestMethod -Uri $uploadUrl -Method POST -Headers $headers -Body $bodyParts.ToArray() -TimeoutSec 120

    if ($response.success) {
        $records = if ($response.records) { " ($($response.records) registros)" } else { "" }
        Write-Log "OK: $($response.message)$records" "OK"
        exit 0
    } else {
        Write-Log "Error del servidor: $($response.error)" "ERROR"
        exit 1
    }
} catch {
    Write-Log "Excepción: $_" "ERROR"
    exit 1
}
