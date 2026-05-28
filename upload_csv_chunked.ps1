<#
.SYNOPSIS
  Upload a large CSV file to api.php using chunked POST requests.

.DESCRIPTION
  This script reads a CSV file in chunks and uploads it to:
      api.php?action=upload_csv_chunk

  After upload completion, it triggers:
      api.php?action=process_staging_csv

  Useful when the server rejects large multipart uploads.

.PARAMETER FilePath
  Local path of the CSV file.

.PARAMETER Endpoint
  Upload endpoint URL.

.PARAMETER ProcessEndpoint
  Server-side processing endpoint URL.

.PARAMETER Secret
  Optional upload secret.

.PARAMETER UploadId
  Optional upload session ID.

.PARAMETER ChunkSize
  Chunk size in bytes.
#>

param(
    [Parameter(Mandatory = $true)]
    [string]$FilePath,

    [string]$Endpoint = 'http://localhost/Lensware_monitor-main/api.php?action=upload_csv_chunk',

    [string]$ProcessEndpoint = 'http://localhost/Lensware_monitor-main/api.php?action=process_staging_csv',

    [string]$Secret = '',

    [string]$UploadId = '',

    [int]$ChunkSize = 200000
)

# ------------------------------------------------------------
# Validate file
# ------------------------------------------------------------

if (-not (Test-Path $FilePath)) {
    Write-Error "El archivo no existe: $FilePath"
    exit 1
}

$filename = [System.IO.Path]::GetFileName($FilePath)

if (-not $UploadId) {
    $UploadId = [guid]::NewGuid().ToString('N')
}

# ------------------------------------------------------------
# Open stream
# ------------------------------------------------------------

try {
    $stream = [System.IO.File]::OpenRead($FilePath)
}
catch {
    Write-Error "No se pudo abrir el archivo: $($_.Exception.Message)"
    exit 1
}

$totalChunks = [math]::Ceiling([double]$stream.Length / $ChunkSize)

if ($totalChunks -eq 0) {
    Write-Error "El archivo está vacío o no se pudo leer."
    $stream.Close()
    exit 1
}

Write-Host ""
Write-Host "========================================="
Write-Host "CSV Chunk Upload Started"
Write-Host "========================================="
Write-Host "File:         $filename"
Write-Host "Size:         $($stream.Length) bytes"
Write-Host "Chunk Size:   $ChunkSize bytes"
Write-Host "Chunks:       $totalChunks"
Write-Host "Upload ID:    $UploadId"
Write-Host "========================================="
Write-Host ""

$chunkIndex = 0

try {

    while ($chunkIndex -lt $totalChunks) {

        $remaining = $stream.Length - $stream.Position
        $bytesToRead = [int][math]::Min($ChunkSize, $remaining)

        $buffer = New-Object byte[] $bytesToRead

        $read = $stream.Read($buffer, 0, $bytesToRead)

        if ($read -le 0) {
            break
        }

        # Fix partial read issue
        if ($read -lt $buffer.Length) {

            $trimmed = New-Object byte[] $read

            [Array]::Copy($buffer, $trimmed, $read)

            $buffer = $trimmed
        }

        # ------------------------------------------------------------
        # Build URL safely
        # ------------------------------------------------------------

        $separator = if ($Endpoint.Contains('?')) { '&' } else { '?' }

        $uri = [string]::Format(
            '{0}{1}filename={2}&upload_id={3}&chunk_index={4}&chunk_count={5}&chunk_size={6}',
            $Endpoint,
            $separator,
            [uri]::EscapeDataString($filename),
            [uri]::EscapeDataString($UploadId),
            $chunkIndex,
            $totalChunks,
            $read
        )

        # ------------------------------------------------------------
        # Headers
        # ------------------------------------------------------------

        $headers = @{}

        if ($Secret -ne '') {
            $headers['X-Upload-Secret'] = $Secret
        }

        # ------------------------------------------------------------
        # Progress
        # ------------------------------------------------------------

        $percent = [math]::Round((($chunkIndex + 1) / $totalChunks) * 100, 2)

        Write-Progress `
            -Activity "Uploading CSV" `
            -Status "Chunk $($chunkIndex + 1) of $totalChunks" `
            -PercentComplete $percent

        Write-Host ("Uploading chunk {0}/{1} ({2}%)..." -f `
            ($chunkIndex + 1), `
            $totalChunks, `
            $percent)

        # ------------------------------------------------------------
        # Retry upload
        # ------------------------------------------------------------

        $maxRetries = 3
        $attempt = 1
        $uploaded = $false

        while (-not $uploaded -and $attempt -le $maxRetries) {

            try {

                $response = Invoke-RestMethod `
                    -Uri $uri `
                    -Method Post `
                    -Headers $headers `
                    -Body $buffer `
                    -ContentType 'application/octet-stream' `
                    -TimeoutSec 300 `
                    -ErrorAction Stop

                $uploaded = $true
            }
            catch {

                Write-Warning ("Intento {0}/{1} falló: {2}" -f `
                    $attempt, `
                    $maxRetries, `
                    $_.Exception.Message)

                if ($attempt -ge $maxRetries) {
                    throw
                }

                Start-Sleep -Seconds 2
                $attempt++
            }
        }

        # ------------------------------------------------------------
        # Validate response
        # ------------------------------------------------------------

        if (-not $response.success) {

            $serverError = if ($response.error) {
                $response.error
            }
            else {
                'Unknown server error'
            }

            Write-Error ("El servidor respondió con error en fragmento {0}: {1}" -f `
                $chunkIndex, `
                $serverError)

            exit 1
        }

        $chunkIndex++
    }

    Write-Progress -Activity "Uploading CSV" -Completed

    Write-Host ""
    Write-Host "========================================="
    Write-Host "UPLOAD COMPLETED"
    Write-Host "========================================="
    Write-Host "File uploaded successfully: $filename"

    if ($response.success -and $response.message) {
        Write-Host "Server message: $($response.message)"
    }

    Write-Host ""

    # ------------------------------------------------------------
    # Process CSV on server
    # ------------------------------------------------------------

    Write-Host "Processing CSV file on server..."

    $processSeparator = if ($ProcessEndpoint.Contains('?')) { '&' } else { '?' }

    $processUri = "$ProcessEndpoint${processSeparator}file=$([uri]::EscapeDataString($filename))"

    if ($Secret -ne '') {
        $processUri += "&secret=$([uri]::EscapeDataString($Secret))"
    }

    $processHeaders = @{}

    if ($Secret -ne '') {
        $processHeaders['X-Upload-Secret'] = $Secret
    }

    try {

        $processResponse = Invoke-RestMethod `
            -Uri $processUri `
            -Method Post `
            -Headers $processHeaders `
            -ContentType 'application/json' `
            -TimeoutSec 300 `
            -ErrorAction Stop

        if ($processResponse.success) {

            Write-Host ""
            Write-Host "========================================="
            Write-Host "PROCESSING COMPLETED"
            Write-Host "========================================="

            if ($processResponse.message) {
                Write-Host "Message: $($processResponse.message)"
            }

            $records = 'N/A'

            if ($processResponse.data -and $processResponse.data.records) {
                $records = $processResponse.data.records
            }

            Write-Host "Records processed: $records"

            if ($processResponse.data) {
                Write-Host ""
                Write-Host "Response data:"
                $processResponse.data | ConvertTo-Json -Depth 5
            }

            Write-Host ""
            Write-Host "✓ CSV upload and processing completed successfully."
        }
        else {

            $processError = if ($processResponse.error) {
                $processResponse.error
            }
            else {
                'Unknown processing error'
            }

            Write-Error "Processing failed: $processError"
            exit 1
        }
    }
    catch {

        Write-Error ("Error processing file on server: {0}" -f `
            $_.Exception.Message)

        exit 1
    }
}
catch {

    Write-Error ("Fatal error: {0}" -f $_.Exception.Message)

    exit 1
}
finally {

    if ($stream) {
        $stream.Close()
        $stream.Dispose()
    }
}