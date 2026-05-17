<?php
// includes/functions.php - Funciones principales (Railway-ready)

require_once __DIR__ . '/../config.php';

function findLatestCSV(): ?string {
    $folder = WATCH_FOLDER;
    if (!is_dir($folder)) return null;
    $latest = null; $latestTs = 0;
    foreach (CSV_PREFIXES as $prefix) {
        foreach (array_merge(
            glob($folder . '/' . $prefix . '*.csv') ?: [],
            glob($folder . '/' . $prefix . '*.CSV') ?: []
        ) as $file) {
            $ts = filemtime($file);
            if ($ts > $latestTs) { $latestTs = $ts; $latest = $file; }
        }
    }
    return $latest;
}

// Columnas reales Lensware (TAB separado):
// Job | Date | Time | Status | Text | Batch/Info | User | Device |
// Sg | R/L | Option | Type | DM | Lens description |
// Blank description | Bcrv | Index | Reason | Reason Descr | Dep BR/RM
function processCSV(string $filepath): ?array {
    if (!file_exists($filepath)) {
        logMessage("Archivo no encontrado: $filepath", 'error');
        return null;
    }

    $raw = file_get_contents($filepath);
    if ($raw === false) {
        logMessage("No se pudo leer el archivo: $filepath", 'error');
        return null;
    }

    $raw = ltrim($raw, "\xEF\xBB\xBF");

    $encoding = mb_detect_encoding($raw, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
    }

    $lines = preg_split('/\r\n|\n|\r/', trim($raw));
    if (count($lines) < 2) {
        logMessage("Archivo con menos de 2 líneas: " . count($lines), 'error');
        return null;
    }

    $firstLine  = $lines[0];
    $delimiters = ["\t", ";", ",", "|"];
    $delimiter  = null;
    $maxCount   = 0;

    foreach ($delimiters as $d) {
        $count = substr_count($firstLine, $d);
        if ($count > $maxCount) { $maxCount = $count; $delimiter = $d; }
    }

    if (!$delimiter) {
        logMessage("No se pudo detectar el delimitador", 'error');
        return null;
    }

    logMessage("Delimitador detectado: '" . ($delimiter === "\t" ? "TAB" : $delimiter) . "'");

    $header = array_map('trim', str_getcsv($lines[0], $delimiter));
    if (end($header) === '') array_pop($header);

    logMessage("Cabecera encontrada (" . count($header) . " columnas): " . json_encode($header));

    $requiredCols = ['Job', 'Status', 'Date', 'Time'];
    $missingCols  = [];
    foreach ($requiredCols as $col) {
        if (!in_array($col, $header)) $missingCols[] = $col;
    }

    if (!empty($missingCols)) {
        logMessage("Columnas requeridas faltantes: " . json_encode($missingCols), 'error');
        return null;
    }

    global $STATUS_LABELS;
    $records = [];

    for ($i = 1; $i < count($lines); $i++) {
        $line = rtrim($lines[$i], "\r\n;");
        if ($line === '') continue;

        $cols = str_getcsv($line, $delimiter);

        if (count($cols) > count($header)) {
            $cols = array_slice($cols, 0, count($header));
        } elseif (count($cols) < count($header)) {
            $cols = array_pad($cols, count($header), '');
        }

        $row = array_combine($header, $cols);
        if (!$row) {
            logMessage("Error al combinar fila $i", 'warning');
            continue;
        }

        $job = trim($row['Job'] ?? '');
        if ($job === '') continue;

        $records[] = [
            'job'          => $job,
            'status'       => trim($row['Status'] ?? ''),
            'status_label' => $STATUS_LABELS[trim($row['Status'] ?? '')] ?? trim($row['Status'] ?? ''),
            'is_breakage'  => (trim($row['Status'] ?? '') === 'BREA'),
            'date_raw'     => trim($row['Date'] ?? ''),
            'time_raw'     => trim($row['Time'] ?? ''),
            'user'         => trim($row['User'] ?? ''),
            'device'       => trim($row['Device'] ?? ''),
            'side'         => trim($row['R/L'] ?? ''),
            'side_label'   => match(trim($row['R/L'] ?? '')) { 'R' => 'OD', 'L' => 'OI', default => trim($row['R/L'] ?? '') },
            'lens_desc'    => trim($row['Lens description'] ?? ''),
            'blank_desc'   => trim($row['Blank description'] ?? ''),
            'reason'       => trim($row['Reason'] ?? ''),
            'reason_descr' => trim($row['Reason Descr'] ?? ''),
            'dep'          => trim($row['Dep BR/RM'] ?? ''),
            'text'         => trim($row['Text'] ?? ''),
            'batch_info'   => trim($row['Batch/Info'] ?? ''),
            'sg'           => trim($row['Sg'] ?? ''),
            'option'       => trim($row['Option'] ?? ''),
            'type'         => trim($row['Type'] ?? ''),
            'dm'           => trim($row['DM'] ?? ''),
            'bcrv'         => trim($row['Bcrv'] ?? ''),
            'index_val'    => ($indexRaw = trim($row['Index'] ?? '')) !== ''
                ? (float) str_replace(',', '.', $indexRaw)
                : null,
        ];
    }

    if (empty($records)) {
        logMessage("No se encontraron registros válidos en el archivo", 'error');
        return null;
    }

    logMessage("Procesados " . count($records) . " registros exitosamente");
    return ['records' => $records, 'filename' => basename($filepath)];
}

function calculateStats(array $records): array {
    $total = count($records);
    $byStatus = []; $byHour = array_fill(0, 24, 0);
    $byDevice = []; $byUser = []; $byCause = [];
    $jobsSet = []; $jobsBrea = []; $breaDev = []; $breaUser = [];
    $eventos = 0;

    foreach ($records as $r) {
        $s = $r['status'];
        $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;

        $hour = (int)substr($r['time_raw'], 0, 2);
        if ($hour >= 0 && $hour < 24) $byHour[$hour]++;

        $dev = trim($r['device'] ?? '');
        if ($dev !== '') $byDevice[$dev] = ($byDevice[$dev] ?? 0) + 1;

        $usr = $r['user'] ?: 'Desconocido';
        $byUser[$usr] = ($byUser[$usr] ?? 0) + 1;

        $jobsSet[$r['job']] = true;

        if ($r['is_breakage']) {
            $eventos++;
            $jobsBrea[$r['job']] = true;
            $cause = $r['reason_descr'] ?: ($r['reason'] ?: 'Sin causa');
            $byCause[$cause] = ($byCause[$cause] ?? 0) + 1;
            if ($dev !== '') $breaDev[$dev] = ($breaDev[$dev] ?? 0) + 1;
            $breaUser[$usr]  = ($breaUser[$usr]  ?? 0) + 1;
        }
    }

    arsort($byDevice); arsort($byUser); arsort($byCause);

    $jobsUnicos  = count($jobsSet);
    $jobsConBrea = count($jobsBrea);

    return [
        'total'         => $total,
        'jobs_unicos'   => $jobsUnicos,
        'jobs_con_brea' => $jobsConBrea,
        'brea_tasa'     => $jobsUnicos > 0 ? round($jobsConBrea / $jobsUnicos * 100, 2) : 0,
        'eventos_brea'  => $eventos,
        'usuarios'      => count($byUser),
        'dispositivos'  => count($byDevice),
        'lentes_tipos'  => count(array_unique(array_column($records, 'lens_desc'))),
        'por_status'    => $byStatus,
        'por_hora'      => $byHour,
        'por_device'    => $byDevice,
        'por_user'      => $byUser,
        'brea_causa'    => $byCause,
        'brea_device'   => $breaDev,
        'brea_por_user' => $breaUser,
    ];
}

function getBreakages(array $records): array {
    return array_values(array_filter($records, fn($r) => $r['is_breakage']));
}

function getDeviceStats(array $records): array {
    $stats = []; $jobs = [];
    foreach ($records as $r) {
        $dev = trim($r['device'] ?? '');
        if ($dev === '') continue;
        if (!isset($stats[$dev])) {
            $stats[$dev] = ['name' => $dev, 'device' => $dev, 'total' => 0, 'jobs' => 0, 'brea' => 0, 'breakages' => 0, 'rate' => 0];
            $jobs[$dev]  = [];
        }
        $stats[$dev]['total']++;
        $jobs[$dev][$r['job']] = true;
        if ($r['is_breakage']) { $stats[$dev]['brea']++; $stats[$dev]['breakages']++; }
    }
    foreach ($stats as $dev => &$s) {
        $s['jobs'] = count($jobs[$dev]);
        $s['rate'] = $s['jobs'] > 0 ? round($s['brea'] / $s['jobs'] * 100, 2) : 0;
    }
    usort($stats, fn($a, $b) => $b['total'] - $a['total']);
    return array_values($stats);
}

function getDeviceDetails(array $records, string $deviceName): array {
    $filtered = array_values(array_filter($records, fn($r) => $r['device'] === $deviceName));
    $hourDist = array_fill(0, 24, 0); $jobs = []; $brea = 0;
    foreach ($filtered as $r) {
        $hour = (int)substr($r['time_raw'], 0, 2);
        if ($hour >= 0 && $hour < 24) $hourDist[$hour]++;
        if (!isset($jobs[$r['job']])) $jobs[$r['job']] = ['total' => 0, 'brea' => 0];
        $jobs[$r['job']]['total']++;
        if ($r['is_breakage']) { $jobs[$r['job']]['brea']++; $brea++; }
    }
    arsort($jobs);
    return [
        'records'            => $filtered,
        'total_records'      => count($filtered),
        'total_jobs'         => count($jobs),
        'breakages'          => $brea,
        'hour_distribution'  => $hourDist,
        'jobs'               => $jobs,
    ];
}

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
    array_walk_recursive($data, function (&$val) {
        if (is_string($val)) {
            $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
            $val = preg_replace('/[\x00-\x1F\x7F]/u', '', $val);
        }
    });
    $json = json_encode(['timestamp' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE);
    if ($json === false) { logMessage('saveCache error: ' . json_last_error_msg(), 'error'); return false; }
    return file_put_contents(CACHE_FILE, $json, LOCK_EX) !== false;
}

// ─────────────────────────────────────────────────────────────────────────────
// FIX Bug 2: Prevenir backups duplicados
// Antes: comparaba filemtime del CSV vs filemtime del último backup.
// El problema es que cuando se sube un nuevo CSV, el backup se crea con
// filemtime ≈ ahora, pero en la próxima request el CSV también tiene
// filemtime ≈ ahora → volvía a crear un backup innecesario.
// Solución: guardar en un archivo de estado la marca de tiempo del último
// CSV procesado. Solo se hace backup si el CSV cambió desde ese registro.
// ─────────────────────────────────────────────────────────────────────────────
function getLastBackupTimestamp(): int {
    $stateFile = __DIR__ . '/../cache/last_backup.txt';
    if (!file_exists($stateFile)) return 0;
    return (int) trim(file_get_contents($stateFile));
}

function saveLastBackupTimestamp(int $ts): void {
    $stateFile = __DIR__ . '/../cache/last_backup.txt';
    $dir = dirname($stateFile);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents($stateFile, (string)$ts, LOCK_EX);
}

function getLastCSVBackup(string $filepath): ?string {
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) return null;
    $files = glob($dir . '/BACKUP_*_' . basename($filepath)) ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0] ?? null;
}

function hasDailyCSVBackup(string $filepath, DateTimeInterface $date): bool {
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) return false;
    $dailyFile = $dir . '/BACKUP_' . $date->format('Ymd') . '_2359_' . basename($filepath);
    return file_exists($dailyFile);
}

function ensureCSVBackups(string $filepath): void {
    if (!file_exists($filepath)) return;

    $dir = BACKUP_FOLDER;
    // FIX Bug 3: Verificar permisos de escritura antes de intentar crear backups
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            logMessage("No se pudo crear BACKUP_FOLDER: $dir", 'error');
            return;
        }
    }
    if (!is_writable($dir)) {
        logMessage("BACKUP_FOLDER no tiene permisos de escritura: $dir", 'error');
        return;
    }

    $now    = new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica'));
    $csvMts = filemtime($filepath);

    // FIX Bug 2: Usar timestamp persistido en lugar de comparar con filemtime del backup
    $lastBackupTs = getLastBackupTimestamp();
    if ($csvMts > $lastBackupTs) {
        backupCSV($filepath);
        saveLastBackupTimestamp($csvMts);
    }

    // FIX Bug 4: El backup diario igual depende de esta función cuando no hay cron,
    // pero ahora supervisord.conf lo llama cada 5 min vía monitor.php.
    // Esta lógica queda como fallback.
    if ($now->format('Hi') >= '2355' && $now->format('Hi') <= '2359' && !hasDailyCSVBackup($filepath, $now)) {
        backupCSV($filepath, $now->format('Ymd_2359'));
    }
}

function backupCSV(string $filepath, ?string $timestamp = null): void {
    if (!file_exists($filepath)) return;
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            logMessage("backupCSV: no se pudo crear directorio $dir", 'error');
            return;
        }
    }
    $stamp = $timestamp ?? date('Ymd_His');
    $dest  = $dir . '/BACKUP_' . $stamp . '_' . basename($filepath);

    // Evitar sobrescribir un backup que ya existe con el mismo timestamp
    if (file_exists($dest)) {
        logMessage("Backup ya existe, omitido: " . basename($dest));
        return;
    }

    if (!copy($filepath, $dest)) {
        logMessage("backupCSV: falló copy() hacia $dest", 'error');
        return;
    }

    logMessage("Respaldo creado: " . basename($dest));
}

function listBackups(): array {
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/BACKUP_*.csv') ?: [];
    $list  = [];
    foreach ($files as $f) {
        $list[] = [
            'filename' => basename($f),
            'name'     => basename($f),
            'size'     => filesize($f),
            'modified' => date('Y-m-d H:i:s', filemtime($f)),
        ];
    }
    usort($list, fn($a, $b) => strcmp($b['modified'], $a['modified']));
    return $list;
}