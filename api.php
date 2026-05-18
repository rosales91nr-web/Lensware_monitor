<?php
// api.php - API REST (Railway-ready) - VERSIÓN CORREGIDA

error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en producción

// Healthcheck para Railway
if ($_SERVER['REQUEST_URI'] === '/health' || ($_SERVER['PATH_INFO'] ?? '') === '/health') {
    http_response_code(200);
    echo 'OK';
    exit;
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-Upload-Secret');

function respondJson(array $data, int $statusCode = 200): void {
    if (ob_get_length() !== false) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';

// Verificar si la función ya existe antes de incluir functions.php
if (!function_exists('processCSV')) {
    require_once __DIR__ . '/includes/functions.php';
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'status':
            $latestCSV = findLatestCSV();
            respondJson([
                'success' => true,
                'data' => [
                    'monitor_active' => true,
                    'latest_file'    => $latestCSV ? basename($latestCSV) : null,
                    'watch_folder'   => 'uploads/',
                    'environment'    => getenv('RAILWAY_ENVIRONMENT') ?: 'local'
                ]
            ]);

        case 'data':
            $cache = readCache();
            if ($cache && !empty($cache['records'])) {
                respondJson(['success' => true, 'data' => $cache]);
            }
            $sourceFile = findLatestDataSource();
            if (!$sourceFile) {
                respondJson([
                    'success' => false,
                    'error'   => 'No hay datos disponibles. Sube un CSV con el monitor.',
                ], 200);
            }
            if (!isBackupFile($sourceFile)) {
                ensureCSVBackups($sourceFile);
            }
            $result = buildLiveDataPayload($sourceFile);
            if (!$result) {
                respondJson(['success' => false, 'error' => 'Error al procesar el archivo'], 500);
            }
            saveCache($result);
            respondJson(['success' => true, 'data' => $result]);

        case 'refresh':
            if (file_exists(CACHE_FILE)) unlink(CACHE_FILE);
            respondJson(['success' => true, 'message' => 'Caché limpiado']);

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
            $byDate = groupBackupsByDateFromList(listBackups());
            $today  = appTodayDate();
            $result = [];
            foreach ($byDate as $date => $backups) {
                $isToday = ($date === $today);
                $chosen  = pickOfficialBackupMeta($backups, $isToday);
                $result[] = [
                    'date'     => $date,
                    'label'    => $isToday ? 'Hoy' : date('d/m/Y', strtotime($date)),
                    'is_today' => $isToday,
                    'backup'   => $chosen,
                    'all'      => $backups,
                ];
            }
            respondJson(['success' => true, 'data' => $result]);

        case 'backup_data':
            $filename = basename($_GET['file'] ?? '');
            if ($filename === '' || !str_starts_with($filename, 'BACKUP_')) {
                respondJson(['success' => false, 'error' => 'Archivo no válido'], 400);
            }
            $filepath = BACKUP_FOLDER . '/' . $filename;
            if (!file_exists($filepath)) {
                respondJson(['success' => false, 'error' => 'Backup no encontrado'], 404);
            }
            $data = processCSV($filepath);
            if (!$data || empty($data['records'])) {
                respondJson(['success' => false, 'error' => 'Error al procesar el backup'], 500);
            }
            $records = $data['records'];
            $dateFilter = trim($_GET['date_filter'] ?? '');
            if ($dateFilter !== '') {
                $records = array_values(array_filter($records, function($r) use ($dateFilter) {
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
            $requireAuth = (UPLOAD_SECRET !== 'changeme' && UPLOAD_SECRET !== '');
            if ($requireAuth) {
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
                if (str_starts_with($origName, $prefix)) { $validPrefix = true; break; }
            }
            if (!$validPrefix || strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'csv') {
                respondJson(['success' => false, 'error' => 'Archivo no válido'], 400);
            }
            $dest = WATCH_FOLDER . '/' . $origName;
            if (file_exists($dest)) {
                backupCSV($dest);
            }
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                respondJson(['success' => false, 'error' => 'Error al guardar archivo'], 500);
            }
            ensureCSVBackups($dest);
            $payload = buildLiveDataPayload($dest);
            $cached  = $payload && saveCache($payload);
            logMessage("CSV subido: $origName" . ($cached ? ' (caché actualizada)' : ''));
            respondJson([
                'success' => true,
                'message' => "CSV recibido: $origName",
                'cached'  => $cached,
                'records' => $cached ? count($payload['records']) : 0,
            ]);

        case 'export':
            $cache = readCache();
            if (!$cache || empty($cache['records'])) {
                respondJson(['success' => false, 'error' => 'No hay datos']);
            }
            $type     = $_GET['type'] ?? 'activity';
            $filename = 'lensware_export_' . date('Ymd_His') . '.csv';
            if (ob_get_length() !== false) ob_clean();
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

        default:
            respondJson(['success' => false, 'error' => 'Acción no válida'], 400);
    }
} catch (Exception $e) {
    logMessage($e->getMessage(), 'error');
    respondJson(['success' => false, 'error' => $e->getMessage()], 500);
}