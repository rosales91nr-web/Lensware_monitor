<?php
// api.php - API REST Lensware Pro (Compatibilidad total con Railway)

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ── Captura errores fatales y los convierte en JSON limpio ──────────────────
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error'   => 'PHP fatal: ' . $e['message'],
            'file'    => basename($e['file']),
            'line'    => $e['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
});

// ── Convierte E_WARNING / E_NOTICE en excepciones para atraparlos ───────────
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-Upload-Secret');

function respondJson(array $data, int $statusCode = 200): void
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';

if (!function_exists('processCSV')) {
    require_once __DIR__ . '/includes/functions.php';
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'status':
            $latestCSV = null;
            $cache = null;
            
            try {
                $latestCSV = findLatestDataSource();
            } catch (Throwable $e) {
                // Silencioso: no hay CSV
            }
            
            try {
                $cache = readCache();
            } catch (Throwable $e) {
                // Silencioso: no hay caché
            }
            
            respondJson([
                'success' => true,
                'data'    => [
                    'monitor_active'      => ($latestCSV !== null),
                    'reports_accessible'  => false,
                    'watch_folder'        => WATCH_FOLDER,
                    'staging_folder'      => STAGING_FOLDER,
                    'backup_folder'       => BACKUP_FOLDER,
                    'latest_file'         => $latestCSV ? basename($latestCSV) : null,
                    'latest_modified'     => $latestCSV ? date('Y-m-d H:i:s', @filemtime($latestCSV) ?: time()) : null,
                    'cache_records'       => $cache ? count($cache['records'] ?? []) : 0,
                    'cache_age_seconds'   => 0,
                    'environment'         => APP_ENV,
                ],
            ]);
            break;

        case 'sync':
            try {
                $result = syncLiveData(true);
                if (!$result['success']) {
                    respondJson(['success' => false, 'error' => $result['error']], 200);
                }
                respondJson(['success' => true, 'data' => $result]);
            } catch (Throwable $e) {
                respondJson(['success' => false, 'error' => $e->getMessage()], 200);
            }
            break;

        case 'data':
            try {
                $latest = null;
                $cache = null;
                
                try {
                    $latest = findLatestDataSource();
                } catch (Throwable $e) {
                    // No hay CSV
                }
                
                try {
                    $cache = readCache();
                } catch (Throwable $e) {
                    // No hay caché
                }
                
                $needsSync = !$cache || empty($cache['records']);

                if ($latest && !$needsSync && file_exists(CACHE_FILE)) {
                    $sourceMtime = @filemtime($latest) ?: 0;
                    $cacheMtime = filemtime(CACHE_FILE);
                    if ($sourceMtime > $cacheMtime) {
                        $needsSync = true;
                    }
                }

                if (!$needsSync && file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) <= CACHE_TTL) {
                    respondJson(['success' => true, 'data' => $cache]);
                }

                if ($latest) {
                    $sync = syncLiveData(false);
                    if ($sync['success']) {
                        $fresh = readCache();
                        if ($fresh) {
                            respondJson(['success' => true, 'data' => $fresh]);
                        }
                    }
                }

                if ($cache && !empty($cache['records'])) {
                    respondJson(['success' => true, 'data' => $cache]);
                }

                // ✅ Respuesta por defecto para Railway (sin datos)
                $emptyStats = [
                    'total' => 0,
                    'jobs_unicos' => 0,
                    'brea_tasa' => 0,
                    'por_status' => [],
                    'por_hora' => array_fill(0, 24, 0),
                    'por_device' => [],
                    'usuarios' => 0,
                    'dispositivos' => 0,
                    'total_lentes_brea' => 0,
                    'jobs_con_brea' => 0,
                    'jobs_unicos_afectados' => 0,
                    'brea_causa' => [],
                    'top_jobs_brea' => [],
                ];
                
                respondJson([
                    'success' => true,
                    'data' => [
                        'records' => [],
                        'stats' => $emptyStats,
                        'breakages' => [],
                        'device_stats' => [],
                        'filename' => null,
                        'data_source' => 'none',
                        'backup_folder' => BACKUP_FOLDER,
                    ],
                    'meta' => ['hint' => 'Sin datos. Sube un CSV via upload_csv o agrega archivos en /data/reports']
                ]);
            } catch (Throwable $e) {
                $emptyStats = [
                    'total' => 0,
                    'jobs_unicos' => 0,
                    'brea_tasa' => 0,
                    'por_status' => [],
                    'por_hora' => array_fill(0, 24, 0),
                    'por_device' => [],
                    'usuarios' => 0,
                    'dispositivos' => 0,
                ];
                respondJson([
                    'success' => true,
                    'data' => [
                        'records' => [],
                        'stats' => $emptyStats,
                        'breakages' => [],
                        'device_stats' => [],
                        'filename' => null,
                    ],
                    'warning' => $e->getMessage()
                ]);
            }
            break;

        case 'refresh':
            try {
                $result = syncLiveData(true);
                respondJson([
                    'success' => $result['success'] ?? false,
                    'message' => ($result['success'] ?? false) ? 'Datos actualizados desde REPORTS' : ($result['error'] ?? 'No hay datos para sincronizar'),
                    'data'    => $result ?? null,
                ]);
            } catch (Throwable $e) {
                respondJson([
                    'success' => false,
                    'message' => 'Entorno sin archivos CSV. Sube datos vía upload_csv.',
                    'data'    => null,
                ]);
            }
            break;

        case 'device':
            $deviceName = trim($_GET['name'] ?? '');
            if ($deviceName === '') {
                respondJson(['success' => false, 'error' => 'Nombre requerido'], 400);
            }
            $cache = readCache();
            if (!$cache || empty($cache['records'])) {
                respondJson(['success' => false, 'error' => 'No hay datos'], 200);
            }
            respondJson(['success' => true, 'details' => getDeviceDetails($cache['records'], $deviceName)]);
            break;

        case 'backups':
            try {
                $backups = listBackups();
                respondJson(['success' => true, 'backups' => $backups]);
            } catch (Throwable $e) {
                respondJson(['success' => true, 'backups' => []]);
            }
            break;

        case 'backups_by_date':
            try {
                $index = loadBackupIndex();
                if (empty($index['files'])) {
                    respondJson(['success' => true, 'data' => []]);
                }
                rebuildBackupIndex();
                respondJson(['success' => true, 'data' => buildBackupsByDateForApi()]);
            } catch (Throwable $e) {
                respondJson(['success' => true, 'data' => []]);
            }
            break;

        case 'hist_live':
            $dateFilter = trim($_GET['date'] ?? appTodayDate());
            $hourFrom   = trim($_GET['hour_from'] ?? '');
            $hourTo     = trim($_GET['hour_to'] ?? '');
            try {
                $payload = buildHistLivePayload($dateFilter, $hourFrom, $hourTo);
                if (!$payload) {
                    respondJson(['success' => true, 'data' => ['records' => [], 'stats' => []]], 200);
                }
                respondJson(['success' => true, 'data' => $payload]);
            } catch (Throwable $e) {
                respondJson(['success' => true, 'data' => ['records' => [], 'stats' => []]]);
            }
            break;

        case 'backup_data':
            $filename = basename($_GET['file'] ?? '');
            if ($filename === '' || !str_starts_with($filename, 'BACKUP_')) {
                respondJson(['success' => false, 'error' => 'Archivo no válido'], 400);
            }
            $filepath = BACKUP_FOLDER . DIRECTORY_SEPARATOR . $filename;
            if (!file_exists($filepath)) {
                respondJson(['success' => false, 'error' => 'Backup no encontrado'], 404);
            }
            $data = processCSV($filepath);
            if (!$data || empty($data['records'])) {
                respondJson(['success' => false, 'error' => 'Error al procesar el backup'], 500);
            }
            $records = $data['records'];
            $dateFilter = trim($_GET['date_filter'] ?? '');
            if ($dateFilter === '') {
                $idx = loadBackupIndex()['files'][$filename] ?? [];
                if (!empty($idx['production_dates']) && count($idx['production_dates']) === 1) {
                    $dateFilter = $idx['production_dates'][0];
                }
            }
            if ($dateFilter !== '') {
                $records = array_values(array_filter($records, function ($r) use ($dateFilter) {
                    $normalized = normalizeRecordDate($r['date_raw'] ?? '');
                    return $normalized !== null && $normalized === $dateFilter;
                }));
            }
            $hourFrom = $_GET['hour_from'] ?? '';
            $hourTo   = $_GET['hour_to'] ?? '';
            $records  = filterRecordsByHourRange(
                $records,
                $hourFrom !== '' ? (int) $hourFrom : null,
                $hourTo !== '' ? (int) $hourTo : null
            );
            if (empty($records)) {
                respondJson(['success' => false, 'error' => 'Sin registros para ese filtro'], 404);
            }
            $result = [
                'records'      => $records,
                'stats'        => calculateStatsCorrected($records),
                'breakages'    => getBreakagesConsolidated($records),
                'device_stats' => getDeviceStats($records),
                'filename'     => $filename,
                'source'       => 'backup',
                'filters'      => [
                    'date_filter' => $dateFilter,
                    'hour_from'   => $hourFrom,
                    'hour_to'     => $hourTo,
                ],
            ];
            respondJson(['success' => true, 'data' => $result]);
            break;

        case 'backup_range':
            ini_set('memory_limit', '1024M');
            ini_set('max_execution_time', 600);
            
            $dateFrom = trim($_GET['date_from'] ?? '');
            $dateTo   = trim($_GET['date_to'] ?? '');
            $hourFrom = trim($_GET['hour_from'] ?? '');
            $hourTo   = trim($_GET['hour_to'] ?? '');
            
            if ($dateFrom === '' || $dateTo === '') {
                respondJson(['success' => false, 'error' => 'Parámetros date_from y date_to requeridos'], 400);
            }
            
            $payload = buildBackupRangePayload($dateFrom, $dateTo, $hourFrom, $hourTo);
            
            if (!$payload) {
                respondJson(['success' => false, 'error' => 'Sin registros en ese rango'], 404);
            }
            if (isset($payload['_error'])) {
                if ($payload['_error'] === 'max_days') {
                    respondJson([
                        'success'   => false,
                        'error'     => 'El rango solicitado (' . $payload['requested'] . ' días) supera el máximo permitido de ' . $payload['max_days'] . ' días.',
                        'max_days'  => $payload['max_days'],
                        'requested' => $payload['requested'],
                    ], 400);
                }
                respondJson(['success' => false, 'error' => 'Error interno al construir el rango.'], 500);
            }
            if (empty($payload['records'])) {
                respondJson(['success' => false, 'error' => 'Sin registros en ese rango'], 404);
            }
            respondJson(['success' => true, 'data' => $payload]);
            break;

        case 'upload_csv':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                respondJson(['success' => false, 'error' => 'Método no permitido'], 405);
            }
            // Aumentar límites para cargas de CSV más grandes.
            @ini_set('upload_max_filesize', '100M');
            @ini_set('post_max_size', '100M');
            @ini_set('memory_limit', '512M');
            @ini_set('max_input_time', '300');
            @ini_set('max_execution_time', '300');
            @set_time_limit(300);

            if (UPLOAD_SECRET !== '') {
                $secret = $_SERVER['HTTP_X_UPLOAD_SECRET'] ?? $_POST['secret'] ?? '';
                if ($secret !== UPLOAD_SECRET) {
                    respondJson(['success' => false, 'error' => 'No autorizado'], 403);
                }
            }
            $file = $_FILES['csv_file'] ?? null;
            $rawBody = '';
            $rawName = trim($_SERVER['HTTP_X_UPLOAD_NAME'] ?? $_GET['name'] ?? $_POST['filename'] ?? $_POST['name'] ?? '');
            if (empty($file) && ($rawBody = @file_get_contents('php://input')) !== false && $rawBody !== '' && $rawName !== '') {
                $origName = basename($rawName);
                $file = ['name' => $origName, 'tmp_name' => '', 'error' => UPLOAD_ERR_OK];
            }

            if (empty($file)) {
                respondJson(['success' => false, 'error' => 'No se recibió archivo'], 400);
            }

            $origName = basename($file['name']);
            $validPrefix = false;
            foreach (CSV_PREFIXES as $prefix) {
                if (str_starts_with($origName, $prefix)) {
                    $validPrefix = true;
                    break;
                }
            }
            if (!$validPrefix || strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'csv') {
                respondJson(['success' => false, 'error' => 'Archivo no válido'], 400);
            }

            $uploadDir = defined('STAGING_FOLDER') && STAGING_FOLDER ? STAGING_FOLDER : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lensware_uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
                @chmod($uploadDir, 0777);
            }

            $dest = $uploadDir . DIRECTORY_SEPARATOR . $origName;
            if (file_exists($dest)) {
                @unlink($dest);
            }

            $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            $tmpName = $file['tmp_name'] ?? '';
            $saved = false;

            if ($uploadError !== UPLOAD_ERR_OK) {
                if ($uploadError === UPLOAD_ERR_INI_SIZE && $rawBody !== '' && $origName !== '') {
                    $saved = @file_put_contents($dest, $rawBody, LOCK_EX) !== false;
                }
                if (!$saved) {
                    $message = 'Upload error: ' . $uploadError;
                    if ($uploadError === UPLOAD_ERR_INI_SIZE) {
                        $message .= ' (UPLOAD_ERR_INI_SIZE). Ajuste upload_max_filesize/post_max_size en php.ini o la configuración del servidor, o use el envío por fragmentos si el archivo es mayor a 1MB.';
                    }
                    respondJson([
                        'success'             => false,
                        'error'               => $message,
                        'upload_error'        => $uploadError,
                        'tmp_name'            => $tmpName,
                        'dest'                => $dest,
                        'tmp_exists'          => file_exists($tmpName),
                        'tmp_readable'        => is_readable($tmpName),
                        'temp_dir'            => sys_get_temp_dir(),
                        'upload_tmp_dir'      => ini_get('upload_tmp_dir'),
                        'post_max_size'       => ini_get('post_max_size'),
                        'upload_max_filesize' => ini_get('upload_max_filesize'),
                        'writable'            => is_writable($uploadDir),
                        'request_content_type'=> $_SERVER['CONTENT_TYPE'] ?? null,
                    ], 500);
                }
            }

            if ($tmpName !== '' && file_exists($tmpName) && is_uploaded_file($tmpName)) {
                $saved = move_uploaded_file($tmpName, $dest);
            }

            if (!$saved && $tmpName !== '' && file_exists($tmpName) && is_readable($tmpName)) {
                $saved = copy($tmpName, $dest);
            }

            if (!$saved && $tmpName !== '' && file_exists($tmpName) && is_readable($tmpName)) {
                $content = @file_get_contents($tmpName);
                if ($content !== false) {
                    $saved = @file_put_contents($dest, $content, LOCK_EX) !== false;
                }
            }

            if (!$saved && $rawBody !== '' && $origName !== '') {
                $saved = @file_put_contents($dest, $rawBody, LOCK_EX) !== false;
            }

            if (!$saved) {
                respondJson([
                    'success'        => false,
                    'error'          => 'Error al guardar archivo temporal',
                    'upload_error'   => $uploadError,
                    'tmp_name'       => $tmpName,
                    'dest'           => $dest,
                    'tmp_exists'     => file_exists($tmpName),
                    'tmp_readable'   => is_readable($tmpName),
                    'tmp_size'       => $tmpName && file_exists($tmpName) ? @filesize($tmpName) : null,
                    'temp_dir'       => sys_get_temp_dir(),
                    'writable'       => is_writable($uploadDir),
                    'is_uploaded'    => $tmpName !== '' ? is_uploaded_file($tmpName) : false,
                    'dest_parent_ok' => is_dir(dirname($dest)) && is_writable(dirname($dest))
                ], 500);
            }

$backupSync = ensureCSVBackups($dest);

$payload = buildLiveDataPayload($dest);

$cached = $payload && saveCache($payload);

logMessage(
    "CSV importado manualmente: $origName" .
    ($cached ? ' (caché actualizada)' : '')
);

$msg = "CSV importado: $origName";

if (!empty($backupSync['dates'])) {
    $msg .= ' · Respaldos: ' . count($backupSync['dates']) . ' día(s)';

    if (!empty($backupSync['replaced'])) {
        $msg .= ' (' . count($backupSync['replaced']) . ' reemplazado(s))';
    }
}

respondJson([
    'success'     => true,
    'message'     => $msg,
    'cached'      => $cached,
    'records'     => $cached ? count($payload['records']) : 0,
    'backup_sync' => $backupSync,
    'upload_path' => $dest
]);

break;

        case 'upload_csv_chunk':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                respondJson(['success' => false, 'error' => 'Método no permitido'], 405);
            }
            // Aumentar límites para cargas por fragmentos.
            @ini_set('upload_max_filesize', '100M');
            @ini_set('post_max_size', '100M');
            @ini_set('memory_limit', '512M');
            @ini_set('max_input_time', '300');
            @ini_set('max_execution_time', '300');
            @set_time_limit(300);

            if (UPLOAD_SECRET !== '') {
                $secret = $_SERVER['HTTP_X_UPLOAD_SECRET'] ?? $_POST['secret'] ?? '';
                if ($secret !== UPLOAD_SECRET) {
                    respondJson(['success' => false, 'error' => 'No autorizado'], 403);
                }
            }

            $origName = basename(trim($_POST['filename'] ?? $_GET['filename'] ?? ''));
            $chunkIndex = isset($_POST['chunk_index']) ? (int) $_POST['chunk_index'] : null;
            $chunkCount = isset($_POST['chunk_count']) ? (int) $_POST['chunk_count'] : null;
            $chunkSize  = isset($_POST['chunk_size']) ? (int) $_POST['chunk_size'] : null;

            if ($origName === '' || $chunkIndex === null || $chunkCount === null) {
                respondJson(['success' => false, 'error' => 'Parámetros de chunk incompletos'], 400);
            }

            $validPrefix = false;
            foreach (CSV_PREFIXES as $prefix) {
                if (str_starts_with($origName, $prefix)) {
                    $validPrefix = true;
                    break;
                }
            }
            if (!$validPrefix || strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'csv') {
                respondJson(['success' => false, 'error' => 'Archivo no válido'], 400);
            }

            $uploadDir = defined('STAGING_FOLDER') && STAGING_FOLDER ? STAGING_FOLDER : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lensware_uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
                @chmod($uploadDir, 0777);
            }

            $dest = $uploadDir . DIRECTORY_SEPARATOR . $origName;
            if ($chunkIndex === 0 && file_exists($dest)) {
                @unlink($dest);
            }

            $chunkData = '';
            if (!empty($_FILES['chunk']['tmp_name']) && is_uploaded_file($_FILES['chunk']['tmp_name'])) {
                $chunkData = @file_get_contents($_FILES['chunk']['tmp_name']);
            }
            if ($chunkData === '' || $chunkData === false) {
                $chunkData = @file_get_contents('php://input');
            }
            if ($chunkData === false || $chunkData === '') {
                respondJson(['success' => false, 'error' => 'No se recibió el fragmento del archivo'], 400);
            }

            $saved = @file_put_contents($dest, $chunkData, FILE_APPEND | LOCK_EX) !== false;
            if (!$saved) {
                respondJson(['success' => false, 'error' => 'No se pudo almacenar el fragmento', 'target' => $dest], 500);
            }

            if ($chunkIndex === $chunkCount - 1) {
                $backupSync = ensureCSVBackups($dest);
                rebuildBackupIndex();
                $payload = buildLiveDataPayload($dest);
                $cached = $payload && saveCache($payload);
                $msg = "CSV importado: $origName";
                if (!empty($backupSync['dates'])) {
                    $msg .= ' · Respaldos: ' . count($backupSync['dates']) . ' día(s)';
                    if (!empty($backupSync['replaced'])) {
                        $msg .= ' (' . count($backupSync['replaced']) . ' reemplazado(s))';
                    }
                }
                respondJson([
                    'success'     => true,
                    'message'     => $msg,
                    'cached'      => $cached,
                    'records'     => $cached ? count($payload['records']) : 0,
                    'backup_sync' => $backupSync,
                    'upload_path' => $dest,
                    'chunk'       => $chunkIndex + 1,
                    'chunk_count' => $chunkCount,
                ]);
                break;
            }

            respondJson([
                'success'      => true,
                'message'      => 'Fragmento recibido',
                'chunk_index'  => $chunkIndex,
                'chunk_count'  => $chunkCount,
                'upload_path'  => $dest,
            ]);
            break;

        case 'export':
            $cache = readCache();
            if (!$cache || empty($cache['records'])) {
                respondJson(['success' => false, 'error' => 'No hay datos'], 200);
            }
            $type     = $_GET['type'] ?? 'activity';
            $filename = 'lensware_export_' . date('Ymd_His') . '.csv';
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            if ($type === 'breakages') {
                $uniqueBreakages = getBreakagesConsolidated($cache['records']);
                fputcsv($output, ['Job', 'Fecha', 'Hora', 'OD/OI', 'Causa', 'Usuario', 'Lente', 'Blank description']);
                foreach ($uniqueBreakages as $b) {
                    fputcsv($output, [
                        $b['job'], $b['date_raw'], $b['time_raw'],
                        $b['side_label'], $b['reason_descr'] ?? '',
                        $b['user'] ?? '', $b['lens_desc'] ?? '',
                        formatBlankDescription($b),
                    ]);
                }
            } else {
                fputcsv($output, ['Job', 'Fecha', 'Hora', 'Status', 'Usuario', 'Dispositivo', 'Lado', 'Lente', 'Blank description']);
                foreach ($cache['records'] as $r) {
                    fputcsv($output, [
                        $r['job'], $r['date_raw'], $r['time_raw'],
                        $r['status_label'], $r['user'] ?? '', $r['device'] ?? '',
                        $r['side_label'], $r['lens_desc'] ?? '',
                        formatBlankDescription($r),
                    ]);
                }
            }
            fclose($output);
            exit;
            break;

        case 'search_job':
            $job = trim($_GET['job'] ?? '');
            if (strlen($job) < 2) {
                respondJson(['success' => false, 'error' => 'Ingresa al menos 2 dígitos del Job'], 400);
            }
            $result = searchJobHistory($job);
            if ($result['total_records'] === 0) {
                respondJson(['success' => false, 'error' => "No se encontró el Job «{$job}»"], 404);
            }
            respondJson(['success' => true, 'data' => $result]);
            break;

        case 'cleanup_backups':
            $secret = $_SERVER['HTTP_X_UPLOAD_SECRET'] ?? $_GET['secret'] ?? '';
            if (UPLOAD_SECRET !== 'changeme' && $secret !== UPLOAD_SECRET) {
                respondJson(['success' => false, 'error' => 'No autorizado'], 403);
            }
            require_once __DIR__ . '/cleanup.php';
            $dryRun = ($_GET['dry_run'] ?? '0') === '1';
            $result = cleanupBackupsOnePerDay($dryRun);
            logMessage(
                "cleanup_backups: {$result['deleted']} eliminados, {$result['kept']} conservados, {$result['freed_mb']} MB liberados" .
                ($dryRun ? ' [DRY-RUN]' : '')
            );
            respondJson(['success' => true, 'data' => $result]);
            break;

        case 'process_staging_csv':
            $secret = $_SERVER['HTTP_X_UPLOAD_SECRET'] ?? $_GET['secret'] ?? $_POST['secret'] ?? '';
            if (UPLOAD_SECRET !== 'changeme' && $secret !== UPLOAD_SECRET) {
                respondJson(['success' => false, 'error' => 'No autorizado'], 403);
            }
            $filename = basename($_GET['file'] ?? $_POST['file'] ?? '');
            if ($filename === '') {
                respondJson(['success' => false, 'error' => 'Parámetro file requerido'], 400);
            }
            $filepath = STAGING_FOLDER . DIRECTORY_SEPARATOR . $filename;
            if (!file_exists($filepath)) {
                respondJson(['success' => false, 'error' => 'Archivo no encontrado en staging'], 404);
            }
            $result = ensureCSVBackups($filepath);
            if (!$result['success']) {
                respondJson(['success' => false, 'error' => $result['error'] ?? 'Error al procesar CSV', 'details' => $result], 500);
            }
            rebuildBackupIndex();
            respondJson(['success' => true, 'data' => $result]);
            break;

        case 'setup_dirs':
    $dirs = [STAGING_FOLDER, BACKUP_FOLDER];
    $result = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            $result[$dir] = mkdir($dir, 0777, true) ? 'created' : 'failed';
        } else {
            $result[$dir] = 'already_exists';
        }
    }
    respondJson(['success' => true, 'directories' => $result]);
    break;

        case 'debug_paths':
    $result = [
        'APP_BASE' => defined('APP_BASE') ? APP_BASE : 'no definido',
        'CACHE_FILE' => defined('CACHE_FILE') ? CACHE_FILE : 'no definido',
        'BACKUP_INDEX_FILE' => defined('BACKUP_INDEX_FILE') ? BACKUP_INDEX_FILE : 'no definido',
        'BACKUP_STATE_FILE' => defined('BACKUP_STATE_FILE') ? BACKUP_STATE_FILE : 'no definido',
        'LOG_FILE' => defined('LOG_FILE') ? LOG_FILE : 'no definido',
        'WATCH_FOLDER' => defined('WATCH_FOLDER') ? WATCH_FOLDER : 'no definido',
        'STAGING_FOLDER' => defined('STAGING_FOLDER') ? STAGING_FOLDER : 'no definido',
        'BACKUP_FOLDER' => defined('BACKUP_FOLDER') ? BACKUP_FOLDER : 'no definido',
        'temp_dir' => sys_get_temp_dir(),
        'temp_writable' => is_writable(sys_get_temp_dir()),
    ];
    
    // Verificar cada carpeta
    foreach ([APP_BASE, BACKUP_FOLDER, dirname(CACHE_FILE), dirname(LOG_FILE)] as $dir) {
        if (defined(strtoupper(str_replace('/', '_', $dir)))) continue;
        $result['exists_' . str_replace('/', '_', $dir)] = is_dir($dir);
        $result['writable_' . str_replace('/', '_', $dir)] = is_writable($dir);
    }
    
    respondJson(['success' => true, 'paths' => $result]);
    break;

        case 'fix_permissions_recursive':
    $basePath = '/tmp/lensware';
    $results = [];
    
    // Cambiar propietario y permisos
    $commands = [
        "chmod -R 777 " . escapeshellarg($basePath),
        "chown -R www-data:www-data " . escapeshellarg($basePath) . " 2>/dev/null || true"
    ];
    
    foreach ($commands as $cmd) {
        exec($cmd, $output, $returnCode);
        $results[$cmd] = $returnCode === 0 ? 'success' : 'failed';
    }
    
    // Verificar después del cambio
    $results['after_check'] = [
        'base_writable' => is_writable($basePath),
        'backups_writable' => is_writable($basePath . '/backups'),
        'cache_writable' => is_writable($basePath . '/cache'),
        'logs_writable' => is_writable($basePath . '/logs'),
        'staging_writable' => is_writable($basePath . '/staging'),
    ];
    
    respondJson(['success' => true, 'results' => $results]);
    break;

        case 'fix_permissions_shell':
    $basePath = '/tmp/lensware';
    $results = [];
    
    // Intentar con comandos shell
    $commands = [
        "chmod 777 $basePath",
        "chmod 777 $basePath/staging",
        "chmod 777 $basePath/backups", 
        "chmod 777 $basePath/cache",
        "chmod 777 $basePath/logs",
        "chmod -R 777 $basePath/* 2>/dev/null"
    ];
    
    foreach ($commands as $cmd) {
        $output = [];
        $returnCode = 0;
        exec($cmd . " 2>&1", $output, $returnCode);
        $results[$cmd] = [
            'code' => $returnCode,
            'output' => implode("\n", $output)
        ];
    }
    
    // Verificar resultados
    $results['final_status'] = [
        'base_writable' => is_writable($basePath),
        'staging_writable' => is_writable($basePath . '/staging'),
        'backups_writable' => is_writable($basePath . '/backups'),
        'cache_writable' => is_writable($basePath . '/cache'),
        'logs_writable' => is_writable($basePath . '/logs'),
    ];
    
    respondJson(['success' => true, 'results' => $results]);
    break;

        default:
            respondJson(['success' => false, 'error' => 'Acción no válida'], 400);
    }
} catch (Throwable $e) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    $msg = $e->getMessage();
    $msg = preg_replace('#[A-Za-z]:[/\\\\][^ ]*#', '[path]', $msg);
    $msg = preg_replace('#/[^ ]*#', '[path]', $msg);
    echo json_encode([
        'success' => false,
        'error'   => $msg,
        'type'    => basename(str_replace('\\', '/', get_class($e))),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
