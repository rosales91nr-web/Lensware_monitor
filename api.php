<?php
// api.php - API REST (Railway-ready)
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

            // Limpiar cualquier output previo antes de enviar el archivo
            if (ob_get_length() !== false) ob_clean();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;

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
            fwrite($output, "\xEF\xBB\xBF"); // BOM UTF-8

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
                        $r['job'],        $r['date_raw'],    $r['time_raw'],
                        $r['status_label'], $r['user'] ?? '', $r['device'] ?? '',
                        $r['side_label'], $r['lens_desc'] ?? ''
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