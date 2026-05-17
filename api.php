<?php
// api.php - API REST (Railway-ready)
error_reporting(E_ALL);
ini_set('display_errors', 0); // producción: no mostrar errores en pantalla

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-Upload-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
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
            echo json_encode([
                'success' => true,
                'data' => [
                    'monitor_active' => true,
                    'latest_file'    => $latestCSV ? basename($latestCSV) : null,
                    'watch_folder'   => 'uploads/',
                    'environment'    => getenv('RAILWAY_ENVIRONMENT') ?: 'local'
                ]
            ]);
            break;

        // ------------------------------------------------------------------ //
        case 'data':
            $cache = readCache();
            if ($cache && !empty($cache['records'])) {
                echo json_encode(['success' => true, 'data' => $cache]);
                break;
            }

            $latestCSV = findLatestCSV();
            if (!$latestCSV) {
                echo json_encode(['success' => false, 'error' => 'No se encontró archivo CSV en uploads/']);
                break;
            }

            $data = processCSV($latestCSV);
            if (!$data || empty($data['records'])) {
                echo json_encode(['success' => false, 'error' => 'Error al procesar CSV']);
                break;
            }

            $result = [
                'records'      => $data['records'],
                'stats'        => calculateStats($data['records']),
                'breakages'    => getBreakages($data['records']),
                'device_stats' => getDeviceStats($data['records']),
                'filename'     => $data['filename']
            ];

            saveCache($result);
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        // ------------------------------------------------------------------ //
        case 'refresh':
            if (file_exists(CACHE_FILE)) unlink(CACHE_FILE);
            $latestCSV = findLatestCSV();
            if ($latestCSV) backupCSV($latestCSV);
            echo json_encode(['success' => true, 'message' => 'Caché limpiado']);
            break;

        // ------------------------------------------------------------------ //
        case 'device':
            $deviceName = trim($_GET['name'] ?? '');
            if ($deviceName === '') {
                echo json_encode(['success' => false, 'error' => 'Nombre requerido']);
                break;
            }
            $cache = readCache();
            if (!$cache || empty($cache['records'])) {
                echo json_encode(['success' => false, 'error' => 'No hay datos']);
                break;
            }
            echo json_encode(['success' => true, 'details' => getDeviceDetails($cache['records'], $deviceName)]);
            break;

        // ------------------------------------------------------------------ //
        case 'backups':
            echo json_encode(['success' => true, 'backups' => listBackups()]);
            break;

        // ------------------------------------------------------------------ //
        // Subida de CSV desde Windows (script bat/powershell en el servidor Lensware)
        // POST api.php?action=upload_csv
        // Header: X-Upload-Secret: <UPLOAD_SECRET>
        // Body: multipart/form-data, campo "csv_file"
        // ------------------------------------------------------------------ //
        case 'upload_csv':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Método no permitido']);
                break;
            }

            // Si UPLOAD_SECRET es 'changeme' o vacío, no se requiere auth (uso interno).
            // Para producción pública, define UPLOAD_SECRET en Railway Variables.
            $requireAuth = (UPLOAD_SECRET !== 'changeme' && UPLOAD_SECRET !== '');
            if ($requireAuth) {
                $secret = $_SERVER['HTTP_X_UPLOAD_SECRET'] ?? $_POST['secret'] ?? '';
                if ($secret !== UPLOAD_SECRET) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'No autorizado']);
                    break;
                }
            }

            if (empty($_FILES['csv_file'])) {
                echo json_encode(['success' => false, 'error' => 'No se recibió archivo']);
                break;
            }

            $file     = $_FILES['csv_file'];
            $origName = basename($file['name']);

            // Validar extensión y prefijo
            $validPrefix = false;
            foreach (CSV_PREFIXES as $prefix) {
                if (str_starts_with($origName, $prefix)) { $validPrefix = true; break; }
            }
            if (!$validPrefix || strtolower(pathinfo($origName, PATHINFO_EXTENSION)) !== 'csv') {
                echo json_encode(['success' => false, 'error' => 'Archivo no válido: ' . $origName]);
                break;
            }

            $dest = WATCH_FOLDER . '/' . $origName;

            // Guardar respaldo del anterior si existe
            if (file_exists($dest)) {
                backupCSV($dest);
            }

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                echo json_encode(['success' => false, 'error' => 'Error al guardar archivo']);
                break;
            }

            // Invalidar caché para que se regenere con el nuevo CSV
            if (file_exists(CACHE_FILE)) unlink(CACHE_FILE);

            logMessage("CSV subido: $origName");
            echo json_encode(['success' => true, 'message' => "CSV recibido: $origName"]);
            break;

        // ------------------------------------------------------------------ //
        case 'export':
            $cache = readCache();
            if (!$cache || empty($cache['records'])) {
                echo json_encode(['success' => false, 'error' => 'No hay datos']);
                break;
            }

            $type     = $_GET['type'] ?? 'activity';
            $filename = 'lensware_export_' . date('Ymd_His') . '.csv';

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
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Acción no válida: ' . htmlspecialchars($action)]);
            break;
    }
} catch (Exception $e) {
    logMessage($e->getMessage(), 'error');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
