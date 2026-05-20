<?php
// api.php - API REST Lensware Pro (XAMPP local)

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-Upload-Secret');

function respondJson(array $data, int $statusCode = 200): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }
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
            $latestCSV = findLatestDataSource();
            $cache     = readCache();
            respondJson([
                'success' => true,
                'data'    => [
                    'monitor_active'      => isWatchFolderAccessible() || ($latestCSV !== null),
                    'reports_accessible'  => isWatchFolderAccessible(),
                    'watch_folder'        => WATCH_FOLDER,
                    'staging_folder'      => STAGING_FOLDER,
                    'backup_folder'       => BACKUP_FOLDER,
                    'latest_file'         => $latestCSV ? basename($latestCSV) : null,
                    'latest_modified'     => $latestCSV ? date('Y-m-d H:i:s', @filemtime($latestCSV) ?: time()) : null,
                    'cache_records'       => $cache ? count($cache['records'] ?? []) : 0,
                    'cache_age_seconds'   => file_exists(CACHE_FILE) ? (time() - filemtime(CACHE_FILE)) : null,
                    'environment'         => APP_ENV,
                ],
            ]);

        case 'sync':
            $result = syncLiveData(true);
            if (!$result['success']) {
                respondJson(['success' => false, 'error' => $result['error']], 200);
            }
            respondJson(['success' => true, 'data' => $result]);

        case 'data':
            $latest     = findLatestDataSource();
            $cache      = readCache();
            $needsSync  = !$cache || empty($cache['records']);

            if ($latest && !$needsSync && file_exists(CACHE_FILE)) {
                $sourceMtime = @filemtime($latest) ?: 0;
                $cacheMtime  = filemtime(CACHE_FILE);
                if ($sourceMtime > $cacheMtime) {
                    $needsSync = true;
                }
            }

            if (!$needsSync && (time() - filemtime(CACHE_FILE)) <= CACHE_TTL) {
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

            respondJson([
                'success' => false,
                'error'   => 'No hay datos disponibles.',
                'hint'    => isWatchFolderAccessible()
                    ? 'Esperando CSV en REPORTS (UNI_PROD_*.csv).'
                    : 'No se puede leer REPORTS. Verifica red y permisos: ' . WATCH_FOLDER,
            ], 200);

        case 'refresh':
            $result = syncLiveData(true);
            respondJson([
                'success' => $result['success'],
                'message' => $result['success'] ? 'Datos actualizados desde REPORTS' : ($result['error'] ?? 'Error'),
                'data'    => $result,
            ]);

        case 'device':
            $deviceName = trim($_GET['name'] ?? '');
            if ($deviceName === '') {
                respondJson(['success' => false, 'error' => 'Nombre requerido'], 400);
            }
            $cache = readCache();
            if (!$cache || empty($cache['records'])) {
                respondJson(['success' => false, 'error' => 'No hay datos']);
            }
            respondJson(['success' => true, 'details' => getDeviceDetails($cache['records'], $deviceName)]);

        case 'backups':
            respondJson(['success' => true, 'backups' => listBackups()]);

        case 'backups_by_date':
            if (empty(loadBackupIndex()['files'])) {
                rebuildBackupIndex();
            }
            respondJson(['success' => true, 'data' => buildBackupsByDateForApi()]);

        case 'hist_live':
            $dateFilter = trim($_GET['date'] ?? appTodayDate());
            $hourFrom   = trim($_GET['hour_from'] ?? '');
            $hourTo     = trim($_GET['hour_to'] ?? '');
            $payload    = buildHistLivePayload($dateFilter, $hourFrom, $hourTo);
            if (!$payload) {
                respondJson(['success' => false, 'error' => 'Sin registros en vivo para ' . $dateFilter], 404);
            }
            respondJson(['success' => true, 'data' => $payload]);

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

        case 'backup_range':
            $dateFrom = trim($_GET['date_from'] ?? '');
            $dateTo   = trim($_GET['date_to'] ?? '');
            $hourFrom = trim($_GET['hour_from'] ?? '');
            $hourTo   = trim($_GET['hour_to'] ?? '');
            if ($dateFrom === '' || $dateTo === '') {
                respondJson(['success' => false, 'error' => 'Parámetros date_from y date_to requeridos'], 400);
            }
            $payload = buildBackupRangePayload($dateFrom, $dateTo, $hourFrom, $hourTo);
            if (!$payload || empty($payload['records'])) {
                respondJson(['success' => false, 'error' => 'Sin registros en ese rango'], 404);
            }
            respondJson(['success' => true, 'data' => $payload]);

        case 'upload_csv':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                respondJson(['success' => false, 'error' => 'Método no permitido'], 405);
            }
            if (UPLOAD_SECRET !== '') {
                $secret = $_SERVER['HTTP_X_UPLOAD_SECRET'] ?? $_POST['secret'] ?? '';
                if ($secret !== UPLOAD_SECRET) {
                    respondJson(['success' => false, 'error' => 'No autorizado'], 403);
                }
            }
            if (empty($_FILES['csv_file'])) {
                respondJson(['success' => false, 'error' => 'No se recibió archivo'], 400);
            }
            $file     = $_FILES['csv_file'];
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
            $dest = STAGING_FOLDER . DIRECTORY_SEPARATOR . $origName;
            if (file_exists($dest)) {
                backupCSV($dest);
            }
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                respondJson(['success' => false, 'error' => 'Error al guardar archivo'], 500);
            }
            $backupSync = ensureCSVBackups($dest);
            $payload = buildLiveDataPayload($dest);
            $cached  = $payload && saveCache($payload);
            logMessage("CSV importado manualmente: $origName" . ($cached ? ' (caché actualizada)' : ''));
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
            ]);

        case 'export':
            $cache = readCache();
            if (!$cache || empty($cache['records'])) {
                respondJson(['success' => false, 'error' => 'No hay datos']);
            }
            $type     = $_GET['type'] ?? 'activity';
            $filename = 'lensware_export_' . date('Ymd_His') . '.csv';
            if (ob_get_length() !== false) {
                ob_clean();
            }
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

        case 'cleanup_backups':
            // Requiere la misma clave que upload_csv para proteger la operación.
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

        default:
            respondJson(['success' => false, 'error' => 'Acción no válida'], 400);
    }
} catch (Exception $e) {
    logMessage($e->getMessage(), 'error');
    respondJson(['success' => false, 'error' => $e->getMessage()], 500);
}
