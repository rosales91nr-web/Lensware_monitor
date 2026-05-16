<?php
// includes/functions.php - Funciones principales (Railway-ready)

require_once __DIR__ . '/../config.php';

// --------------------------------------------------------------------------
// Busca el CSV más reciente en uploads/ según los prefijos configurados
// --------------------------------------------------------------------------
function findLatestCSV(): ?string {
    $folder = WATCH_FOLDER;
    if (!is_dir($folder)) return null;

    $latest   = null;
    $latestTs = 0;

    foreach (CSV_PREFIXES as $prefix) {
        $pattern = $folder . '/' . $prefix . '*.csv';
        foreach (glob($pattern) as $file) {
            $ts = filemtime($file);
            if ($ts > $latestTs) {
                $latestTs = $ts;
                $latest   = $file;
            }
        }
    }

    return $latest;
}

// --------------------------------------------------------------------------
// Procesa el CSV y devuelve los registros normalizados
// --------------------------------------------------------------------------
function processCSV(string $filepath): ?array {
    if (!file_exists($filepath)) return null;

    // Leer el archivo, detectar encoding y convertir a UTF-8
    $raw = file_get_contents($filepath);
    if ($raw === false) return null;

    $encoding = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
    }

    // Separar líneas, detectar delimitador
    $lines = preg_split('/\r\n|\n|\r/', trim($raw));
    if (count($lines) < 2) return null;

    $header    = str_getcsv($lines[0], ',');
    $delimiter = (count($header) > 3) ? ',' : ';';
    $header    = str_getcsv($lines[0], $delimiter);
    $header    = array_map('trim', $header);

    global $STATUS_LABELS;

    $records = [];
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '') continue;

        $cols = str_getcsv($line, $delimiter);
        if (count($cols) < count($header)) {
            $cols = array_pad($cols, count($header), '');
        }

        $row = array_combine($header, $cols);
        if (!$row) continue;

        // Normalizar campos comunes (ajusta los nombres de columna a tu CSV real)
        $job        = trim($row['JOB_NUMBER']    ?? $row['job']     ?? '');
        $status     = trim($row['STATUS']        ?? $row['status']  ?? '');
        $dateRaw    = trim($row['DATE']          ?? $row['date']    ?? '');
        $timeRaw    = trim($row['TIME']          ?? $row['time']    ?? '');
        $user       = trim($row['USER']          ?? $row['user']    ?? '');
        $device     = trim($row['DEVICE']        ?? $row['device']  ?? '');
        $side       = trim($row['SIDE']          ?? $row['side']    ?? '');
        $lensDesc   = trim($row['LENS_DESC']     ?? $row['lens']    ?? '');
        $blankDesc  = trim($row['BLANK_DESC']    ?? $row['blank']   ?? '');
        $reason     = trim($row['REASON_CODE']   ?? $row['reason']  ?? '');
        $reasonDescr= trim($row['REASON_DESCR']  ?? '');

        if ($job === '') continue;

        $records[] = [
            'job'          => $job,
            'status'       => $status,
            'status_label' => $STATUS_LABELS[$status] ?? $status,
            'date_raw'     => $dateRaw,
            'time_raw'     => $timeRaw,
            'user'         => $user,
            'device'       => $device,
            'side'         => $side,
            'side_label'   => ($side === 'OD') ? 'OD' : (($side === 'OI') ? 'OI' : $side),
            'lens_desc'    => $lensDesc,
            'blank_desc'   => $blankDesc,
            'reason'       => $reason,
            'reason_descr' => $reasonDescr,
        ];
    }

    return ['records' => $records, 'filename' => basename($filepath)];
}

// --------------------------------------------------------------------------
// Estadísticas generales
// --------------------------------------------------------------------------
function calculateStats(array $records): array {
    $total    = count($records);
    $breakages = 0;
    $byStatus  = [];

    foreach ($records as $r) {
        $s = $r['status'];
        $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
        if ($s === 'BREA') $breakages++;
    }

    return [
        'total'          => $total,
        'breakages'      => $breakages,
        'breakage_rate'  => $total > 0 ? round($breakages / $total * 100, 2) : 0,
        'by_status'      => $byStatus,
    ];
}

// --------------------------------------------------------------------------
// Registros de quiebras
// --------------------------------------------------------------------------
function getBreakages(array $records): array {
    return array_values(array_filter($records, fn($r) => $r['status'] === 'BREA'));
}

// --------------------------------------------------------------------------
// Estadísticas por dispositivo
// --------------------------------------------------------------------------
function getDeviceStats(array $records): array {
    $stats = [];
    foreach ($records as $r) {
        $dev = $r['device'] ?: 'Desconocido';
        if (!isset($stats[$dev])) {
            $stats[$dev] = ['device' => $dev, 'total' => 0, 'breakages' => 0];
        }
        $stats[$dev]['total']++;
        if ($r['status'] === 'BREA') $stats[$dev]['breakages']++;
    }
    usort($stats, fn($a, $b) => $b['total'] - $a['total']);
    return array_values($stats);
}

// --------------------------------------------------------------------------
// Detalles de un dispositivo específico
// --------------------------------------------------------------------------
function getDeviceDetails(array $records, string $deviceName): array {
    return array_values(array_filter($records, fn($r) => $r['device'] === $deviceName));
}

// --------------------------------------------------------------------------
// Caché
// --------------------------------------------------------------------------
function readCache(): ?array {
    if (!file_exists(CACHE_FILE)) return null;
    if ((time() - filemtime(CACHE_FILE)) > CACHE_TTL) return null;

    $content = file_get_contents(CACHE_FILE);
    if (!$content) return null;

    $decoded = json_decode($content, true);
    return ($decoded && isset($decoded['data'])) ? $decoded['data'] : null;
}

function saveCache(array $data): bool {
    $dir = dirname(CACHE_FILE);
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    // Limpiar caracteres problemáticos para JSON
    array_walk_recursive($data, function (&$val) {
        if (is_string($val)) {
            $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
            $val = preg_replace('/[\x00-\x1F\x7F]/u', '', $val);
        }
    });

    $json = json_encode(['timestamp' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        logMessage('saveCache: json_encode falló - ' . json_last_error_msg(), 'error');
        return false;
    }

    return file_put_contents(CACHE_FILE, $json, LOCK_EX) !== false;
}

// --------------------------------------------------------------------------
// Respaldo de CSV
// --------------------------------------------------------------------------
function backupCSV(string $filepath): void {
    if (!file_exists($filepath)) return;
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $dest = $dir . '/BACKUP_' . date('Ymd_His') . '_' . basename($filepath);
    copy($filepath, $dest);
    logMessage("Respaldo creado: " . basename($dest));
}

// --------------------------------------------------------------------------
// Listado de respaldos
// --------------------------------------------------------------------------
function listBackups(): array {
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) return [];

    $files = glob($dir . '/BACKUP_*.csv') ?: [];
    $list  = [];
    foreach ($files as $f) {
        $list[] = [
            'filename' => basename($f),
            'size'     => filesize($f),
            'modified' => date('Y-m-d H:i:s', filemtime($f)),
        ];
    }
    usort($list, fn($a, $b) => strcmp($b['modified'], $a['modified']));
    return $list;
}
