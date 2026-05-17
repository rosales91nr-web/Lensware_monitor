<?php
// api.php - API REST (Railway-ready) + Histórico de Backups
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

            $latestCSV = findLatestCSV();
            if (!$latestCSV) {
                respondJson(['success' => false, 'error' => 'No se encontró archivo CSV en uploads/'], 404);
            }

            ensureCSVBackups($latestCSV);

            $data = processCSV($latestCSV);
            if (!$data || empty($data['records'])) {
                respondJson(['success' => false, 'error' => 'Error al procesar CSV'], 500);
            }

            $result = [
                'records'       => $data['records'],
                'stats'         => calculateStats($data['records']),
                'breakages'     => getBreakages($data['records']),
                'device_stats'  => getDeviceStats($data['records']),
                'filename'      => $data['filename'],
                'backup_folder' => BACKUP_FOLDER
            ];

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
        // Retorna backups agrupados por fecha (YYYY-MM-DD), incluyendo
        // el backup más reciente de hoy y el único _2359_ del día anterior.
        // GET api.php?action=backups_by_date
        // ------------------------------------------------------------------ //
        case 'backups_by_date':
            $all = listBackups(); // ya ordenados por modified desc

            // Extraemos fecha de cada backup del nombre: BACKUP_YYYYMMDD_...
            // o del campo modified como fallback
            $byDate = [];
            foreach ($all as $b) {
                $name = $b['filename'];
                // Intentar extraer fecha del nombre: BACKUP_20250517_... o BACKUP_20250517_2359_...
                if (preg_match('/BACKUP_(\d{4})(\d{2})(\d{2})_/', $name, $m)) {
                    $dateKey = "{$m[1]}-{$m[2]}-{$m[3]}";
                } else {
                    $dateKey = substr($b['modified'], 0, 10);
                }
                $byDate[$dateKey][] = $b;
            }

            // Ordenar fechas descendente
            krsort($byDate);

            // Para el histórico solo necesitamos:
            // - Hoy: el backup más reciente (primero en la lista de hoy, ya que listBackups() ordena desc)
            // - Días anteriores: solo el _2359_ (el diario). Si no existe _2359_, el más reciente de ese día.
            $today = (new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica')))->format('Y-m-d');

            $result = [];
            foreach ($byDate as $date => $backups) {
                if ($date === $today) {
                    // Hoy: el más reciente disponible (primero ya que está desc)
                    $result[] = [
                        'date'     => $date,
                        'label'    => 'Hoy',
                        'is_today' => true,
                        'backup'   => $backups[0],
                        'all'      => $backups,  // todos los de hoy para selector por hora
                    ];
                } else {
                    // Días anteriores: preferir el _2359_ (diario oficial)
                    $daily = null;
                    foreach ($backups as $b) {
                        if (str_contains($b['filename'], '_2359_')) {
                            $daily = $b;
                            break;
                        }
                    }
                    $chosen = $daily ?? $backups[0];
                    $result[] = [
                        'date'     => $date,
                        'label'    => date('d/m/Y', strtotime($date)),
                        'is_today' => false,
                        'backup'   => $chosen,
                        'all'      => $backups,
                    ];
                }
            }

            respondJson(['success' => true, 'data' => $result]);

        // ------------------------------------------------------------------ //
        // Procesa un backup específico por nombre de archivo y retorna sus datos.
        // GET api.php?action=backup_data&file=BACKUP_20250517_123456_UNI_PROD...csv
        // Opcionalmente: &date_filter=2025-05-17&hour_from=08&hour_to=16
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

            // Filtro de fecha si se especifica
            $dateFilter = trim($_GET['date_filter'] ?? '');
            if ($dateFilter !== '') {
                $records = array_values(array_filter($records, function($r) use ($dateFilter) {
                    $normalized = normalizeRecordDate($r['date_raw'] ?? '');
                    return $normalized !== null && $normalized === $dateFilter;
                }));
            }

            // Filtro de rango horario
            $hourFrom = $_GET['hour_from'] ?? '';
            $hourTo   = $_GET['hour_to']   ?? '';
            if ($hourFrom !== '' || $hourTo !== '') {
                $from = $hourFrom !== '' ? (int)$hourFrom : 0;
                $to   = $hourTo   !== '' ? (int)$hourTo   : 23;
                $records = array_values(array_filter($records, function($r) use ($from, $to) {
                    return recordHour($r['time_raw'] ?? '') >= $from
                        && recordHour($r['time_raw'] ?? '') <= $to;
                }));
            }

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

            $result = [
                'records'      => $records,
                'stats'        => calculateStats($records),
                'breakages'    => getBreakages($records),
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

            if (file_exists(CACHE_FILE)) unlink(CACHE_FILE);

            logMessage("CSV subido: $origName");
            respondJson(['success' => true, 'message' => "CSV recibido: $origName"]);

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
                fputcsv($output, ['Job', 'Fecha', 'Hora', 'OD/OI', 'Causa', 'Código', 'Usuario', 'Lente', 'Blank', 'Dispositivo']);
                foreach ($cache['breakages'] as $b) {
                    fputcsv($output, [
                        $b['job'],        $b['date_raw'],      $b['time_raw'],
                        $b['side_label'], $b['reason_descr'] ?? '', $b['reason']    ?? '',
                        $b['user'] ?? '', $b['lens_desc']   ?? '', $b['blank_desc'] ?? '',
                        $b['device'] ?? ''
                    ]);
                }
            } else {
                fputcsv($output, ['Job', 'Fecha', 'Hora', 'Status', 'Usuario', 'Dispositivo', 'Lado', 'Lente']);
                foreach ($cache['records'] as $r) {
                    fputcsv($output, [
                        $r['job'],          $r['date_raw'],    $r['time_raw'],
                        $r['status_label'], $r['user'] ?? '',  $r['device'] ?? '',
                        $r['side_label'],   $r['lens_desc'] ?? ''
                    ]);
                }
            }

            fclose($output);
            exit;

        // ------------------------------------------------------------------ //
        default:
            respondJson(['success' => false, 'error' => 'Acción no válida: ' . htmlspecialchars($action)], 400);
    }
} catch (Exception $e) {
    logMessage($e->getMessage(), 'error');
    respondJson(['success' => false, 'error' => $e->getMessage()], 500);
}