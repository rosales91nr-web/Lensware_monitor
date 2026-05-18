<?php
// api.php - API REST (Railway-ready) + Histórico de Backups
// CORREGIDO: Las estadísticas de quiebra ahora cuentan ÓRDENES ÚNICAS, no eventos
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-Upload-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respondJson(array $data, int $statusCode = 200): void {
    if (ob_get_length() !== false) {
        ob_clean();
    }
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ------------------------------------------------------------------ //
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

        // ------------------------------------------------------------------ //
        case 'data':
            $cache = readCache();
            if ($cache && !empty($cache['records'])) {
                respondJson(['success' => true, 'data' => $cache]);
            }

            $sourceFile = findLatestDataSource();
            if (!$sourceFile) {
                respondJson([
                    'success' => false,
                    'error'   => 'No hay datos disponibles. Sube un CSV con el monitor de Windows o Importar CSV.',
                    'hint'    => 'Verifica que el monitor esté activo y UPLOAD_SECRET coincida en Railway.',
                ], 200);
            }

            if (!isBackupFile($sourceFile)) {
                ensureCSVBackups($sourceFile);
            }

            $result = buildLiveDataPayload($sourceFile);
            if (!$result) {
                respondJson(['success' => false, 'error' => 'Error al procesar el archivo de datos'], 500);
            }

            saveCache($result);
            respondJson(['success' => true, 'data' => $result]);

        // ------------------------------------------------------------------ //
        case 'refresh':
            if (file_exists(CACHE_FILE)) unlink(CACHE_FILE);
            $latestCSV = findLatestCSV();
            if ($latestCSV) backupCSV($latestCSV);
            respondJson(['success' => true, 'message' => 'Caché limpiado']);

        // ------------------------------------------------------------------ //
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

        // ------------------------------------------------------------------ //
        case 'backups':
            respondJson(['success' => true, 'backups' => listBackups()]);

        // ------------------------------------------------------------------ //
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

        // ------------------------------------------------------------------ //
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
                $totalInFile = count($data['records']);
                $sampleDate  = $totalInFile > 0
                    ? ($data['records'][0]['date_raw'] ?? 'vacío')
                    : 'vacío';
                respondJson([
                    'success' => false,
                    'error'   => 'Sin registros para ese filtro. '
                        . "El CSV usa fechas como «{$sampleDate}». "
                        . 'Prueba sin rango de hora o elige otro backup.',
                ], 404);
            }

            // 🔧 IMPORTANTE: Usar calculateStatsWithUniqueBreakages para contar órdenes únicas
            $result = [
                'records'      => $records,
                'stats'        => calculateStatsWithUniqueBreakages($records),
                'breakages'    => getUniqueBreakagesByOrder($records),
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

        // ------------------------------------------------------------------ //
        case 'backup_range':
            $dateFrom = trim($_GET['date_from'] ?? '');
            $dateTo   = trim($_GET['date_to'] ?? '');
            $hourFrom = trim($_GET['hour_from'] ?? '');
            $hourTo   = trim($_GET['hour_to'] ?? '');

            if ($dateFrom === '' || $dateTo === '') {
                respondJson(['success' => false, 'error' => 'Parámetros date_from y date_to requeridos (YYYY-MM-DD)'], 400);
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
                respondJson(['success' => false, 'error' => 'Formato de fecha inválido. Use YYYY-MM-DD'], 400);
            }

            if ($hourFrom !== '' && $hourTo !== '' && (int) $hourFrom > (int) $hourTo) {
                respondJson(['success' => false, 'error' => 'La hora de inicio no puede ser mayor que la hora de fin'], 400);
            }

            $payload = buildBackupRangePayload($dateFrom, $dateTo, $hourFrom, $hourTo);

            if (is_array($payload) && isset($payload['_error']) && $payload['_error'] === 'max_days') {
                respondJson([
                    'success' => false,
                    'error'   => "El rango máximo es de {$payload['max_days']} días (solicitaste {$payload['requested']}).",
                    'max_days'=> $payload['max_days'],
                ], 400);
            }

            if (!$payload || empty($payload['records'])) {
                respondJson([
                    'success' => false,
                    'error'   => 'Sin registros en ese rango de fechas/horas. Prueba ampliar el período o quitar el filtro horario.',
                ], 404);
            }

            respondJson(['success' => true, 'data' => $payload]);

        // ------------------------------------------------------------------ //
        case 'download_backup':
            $secret = $_GET['secret'] ?? '';
            if (UPLOAD_SECRET !== 'changeme' && $secret !== UPLOAD_SECRET) {
                respondJson(['success' => false, 'error' => 'No autorizado'], 403);
            }

            $filename = basename($_GET['file'] ?? '');
            if ($filename === '' || !str_starts_with($filename, 'BACKUP_')) {
                respondJson(['success' => false, 'error' => 'Archivo no válido'], 400);
            }

            $filepath = BACKUP_FOLDER . '/' . $filename;
            if (!file_exists($filepath)) {
                respondJson(['success' => false, 'error' => 'Archivo no encontrado'], 404);
            }

            if (ob_get_length() !== false) ob_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;

        // ------------------------------------------------------------------ //
        case 'cleanup_backups':
            $secret = $_GET['secret'] ?? '';
            if (UPLOAD_SECRET !== 'changeme' && $secret !== UPLOAD_SECRET) {
                respondJson(['success' => false, 'error' => 'No autorizado'], 403);
            }
            $result = cleanupOldBackups();
            respondJson([
                'success' => true,
                'message' => "Limpieza completada: {$result['deleted']} eliminados, {$result['kept']} conservados",
                'data'    => $result
            ]);

        // ------------------------------------------------------------------ //
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
                respondJson(['success' => false, 'error' => 'Archivo no válido: ' . $origName], 400);
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

            logMessage("CSV subido: $origName" . ($cached ? ' (caché actualizada)' : ' (sin caché)'));
            respondJson([
                'success' => true,
                'message' => "CSV recibido: $origName",
                'cached'  => $cached,
                'records' => $cached ? count($payload['records']) : 0,
            ]);

        // ------------------------------------------------------------------ //
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
                // Exportar quiebras como ÓRDENES ÚNICAS (consolidadas)
                $uniqueBreakages = getUniqueBreakagesByOrder($cache['records']);
                fputcsv($output, ['Job', 'Fecha', 'Hora', 'OD/OI', 'Causa', 'Usuario', 'Lente', 'Blank description']);
                foreach ($uniqueBreakages as $b) {
                    fputcsv($output, [
                        $b['job'],        $b['date_raw'],      $b['time_raw'],
                        $b['side_label'], $b['reason_descr'] ?? '',
                        $b['user'] ?? '', $b['lens_desc']   ?? '',
                        formatBlankDescription($b),
                    ]);
                }
            } else {
                fputcsv($output, ['Job', 'Fecha', 'Hora', 'Status', 'Usuario', 'Dispositivo', 'Lado', 'Lente', 'Blank description']);
                foreach ($cache['records'] as $r) {
                    fputcsv($output, [
                        $r['job'],          $r['date_raw'],    $r['time_raw'],
                        $r['status_label'], $r['user'] ?? '',  $r['device'] ?? '',
                        $r['side_label'],   $r['lens_desc'] ?? '',
                        formatBlankDescription($r),
                    ]);
                }
            }

            fclose($output);
            exit;

        // ------------------------------------------------------------------ //
        case 'search_job':
            $job = trim($_GET['job'] ?? '');
            if (strlen($job) < 2) {
                respondJson(['success' => false, 'error' => 'Ingresa al menos 2 dígitos del Job'], 400);
            }
            $result = searchJobHistory($job);
            if ($result['total_records'] === 0) {
                respondJson([
                    'success' => false,
                    'error'   => "No se encontró el Job «{$job}» en datos en vivo ni en backups históricos.",
                    'data'    => $result,
                ], 404);
            }
            respondJson(['success' => true, 'data' => $result]);

        // ------------------------------------------------------------------ //
        default:
            respondJson(['success' => false, 'error' => 'Acción no válida: ' . htmlspecialchars($action)], 400);
    }
} catch (Exception $e) {
    logMessage($e->getMessage(), 'error');
    respondJson(['success' => false, 'error' => $e->getMessage()], 500);
}