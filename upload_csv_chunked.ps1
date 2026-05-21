<#
.SYNOPSIS
  Upload a large CSV file to api.php using chunked POST requests.

.DESCRIPTION
  This script reads a CSV in chunks and sends each chunk to api.php?action=upload_csv_chunk.
  It is useful when the server rejects a full multipart upload because the file is too large.

.PARAMETER FilePath
  Local path of the CSV file to upload.

.PARAMETER Endpoint
  API endpoint URL, including action=upload_csv_chunk.

.PARAMETER Secret
  Optional upload secret to send in X-Upload-Secret header.

.PARAMETER ChunkSize
  Chunk size in bytes. Defaults to 256KB.
#>
param(
    [Parameter(Mandatory=$true)]
    [string]$FilePath,

    [string]$Endpoint = 'http://localhost/Lensware_monitor-main/api.php?action=upload_csv_chunk',
    [string]$Secret = '',
    [int]$ChunkSize = 262144
)

if (-not (Test-Path $FilePath)) {
    Write-Error "El archivo no existe: $FilePath"
    exit 1
}

$filename = [System.IO.Path]::GetFileName($FilePath)
$stream = [System.IO.File]::OpenRead($FilePath)
$totalChunks = [math]::Ceiling($stream.Length / $ChunkSize)
$chunkIndex = 0

try {
    while ($chunkIndex -lt $totalChunks) {
        $remaining = $stream.Length - $stream.Position
        $bytesToRead = [int][math]::Min($ChunkSize, $remaining)
        $buffer = New-Object byte[] $bytesToRead
        $read = $stream.Read($buffer, 0, $bytesToRead)

        if ($read -le 0) { break }
        if ($read -lt $buffer.Length) {
            $buffer = $buffer[0..($read - 1)]
        }

        $uri = [string]::Format('{0}&filename={1}&chunk_index={2}&chunk_count={3}&chunk_size={4}', 
            $Endpoint, [uri]::EscapeDataString($filename), $chunkIndex, $totalChunks, $read)

        $headers = @{ 'Content-Type' = 'application/octet-stream' }
        if ($Secret -ne '') {
            $headers['X-Upload-Secret'] = $Secret
        }

        Write-Host "Uploading chunk $($chunkIndex + 1)/$totalChunks..."
        try {
            $response = Invoke-RestMethod -Uri $uri -Method Post -Headers $headers -Body $buffer
        } catch {
            Write-Error "Error al enviar el fragmento $chunkIndex: $_"
            exit 1
        }

        if (-not $response.success) {
            Write-Error "El servidor respondió con error en fragmento $chunkIndex: $($response.error)"
            exit 1
        }

        $chunkIndex++
    }

    Write-Host "Upload complete: $filename"
    if ($response.success) {
        Write-Host "Server message: $($response.message)"
    }
} finally {
    $stream.Close()
}
