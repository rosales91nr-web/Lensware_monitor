<?php
// includes/functions.php - Funciones principales (Railway-ready)

require_once __DIR__ . '/../config.php';

/** Normaliza texto del CSV a UTF-8 legible (evita Mï¿½, Ã³, etc.). */
function sanitizeCsvField(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    // Mojibake: UTF-8 interpretado como Latin-1/Win-1252
    if (preg_match('/ï¿½|Ã[\x80-\xBF]|â€/u', $value)) {
        $fixed = @mb_convert_encoding(
            mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8'),
            'UTF-8',
            'ISO-8859-1'
        );
        if ($fixed && mb_check_encoding($fixed, 'UTF-8') && !preg_match('/ï¿½/u', $fixed)) {
            $value = $fixed;
        }
    }

    return $value;
}

function normalizeCsvEncoding(string $raw): string {
    if (mb_check_encoding($raw, 'UTF-8')) {
        return $raw;
    }
    $encoding = mb_detect_encoding($raw, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        return mb_convert_encoding($raw, 'UTF-8', $encoding);
    }
    return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
}

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

/** Último respaldo BACKUP_*.csv (útil cuando uploads/ está vacío tras redeploy en Railway). */
function findLatestBackupCSV(): ?string {
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) return null;
    $files = glob($dir . '/BACKUP_*.csv') ?: [];
    if (empty($files)) return null;
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0];
}

/** uploads/ primero; si no hay CSV, el backup más reciente. */
function findLatestDataSource(): ?string {
    return findLatestCSV() ?: findLatestBackupCSV();
}

function isBackupFile(string $filepath): bool {
    return str_starts_with(basename($filepath), 'BACKUP_');
}

/** Nombre legible del CSV (sin prefijo BACKUP_fecha_). */
function displayFilename(string $filepath): string {
    $name = basename($filepath);
    if (preg_match('/^BACKUP_\d{8}(?:_\d{4,6})?_(.+)$/i', $name, $m)) {
        return $m[1];
    }
    return $name;
}

/** Construye el payload del dashboard (registros, stats, quiebras, etc.). */
function buildLiveDataPayload(string $filepath): ?array {
    if (!file_exists($filepath)) return null;

    $data = processCSV($filepath);
    if (!$data || empty($data['records'])) return null;

    return [
        'records'       => $data['records'],
        'stats'         => calculateStats($data['records']),
        'breakages'     => getBreakages($data['records']),
        'device_stats'  => getDeviceStats($data['records']),
        'filename'      => displayFilename($filepath),
        'source_file'   => basename($filepath),
        'data_source'   => isBackupFile($filepath) ? 'backup' : 'upload',
        'backup_folder' => BACKUP_FOLDER,
    ];
}

/** Procesa un CSV y guarda caché (tras upload o refresh). */
function warmCacheFromFile(string $filepath): bool {
    $payload = buildLiveDataPayload($filepath);
    if (!$payload) return false;
    return saveCache($payload);
}

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
    $raw = normalizeCsvEncoding($raw);

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

        $job = sanitizeCsvField($row['Job'] ?? '');
        if ($job === '') continue;

        $status = sanitizeCsvField($row['Status'] ?? '');

        $records[] = [
            'job'          => $job,
            'status'       => $status,
            'status_label' => $STATUS_LABELS[$status] ?? $status,
            'is_breakage'  => ($status === 'BREA'),
            'date_raw'     => sanitizeCsvField($row['Date'] ?? ''),
            'time_raw'     => sanitizeCsvField($row['Time'] ?? ''),
            'user'         => sanitizeCsvField($row['User'] ?? ''),
            'device'       => sanitizeCsvField($row['Device'] ?? ''),
            'side'         => sanitizeCsvField($row['R/L'] ?? ''),
            'side_label'   => match(sanitizeCsvField($row['R/L'] ?? '')) { 'R' => 'OD', 'L' => 'OI', default => sanitizeCsvField($row['R/L'] ?? '') },
            'lens_desc'    => sanitizeCsvField($row['Lens description'] ?? ''),
            'blank_desc'   => sanitizeCsvField($row['Blank description'] ?? ''),
            'reason'       => sanitizeCsvField($row['Reason'] ?? ''),
            'reason_descr' => sanitizeCsvField($row['Reason Descr'] ?? ''),
            'dep'          => sanitizeCsvField($row['Dep BR/RM'] ?? ''),
            'text'         => sanitizeCsvField($row['Text'] ?? ''),
            'batch_info'   => sanitizeCsvField($row['Batch/Info'] ?? ''),
            'sg'           => sanitizeCsvField($row['Sg'] ?? ''),
            'option'       => sanitizeCsvField($row['Option'] ?? ''),
            'type'         => sanitizeCsvField($row['Type'] ?? ''),
            'dm'           => sanitizeCsvField($row['DM'] ?? ''),
            'bcrv'         => sanitizeCsvField($row['Bcrv'] ?? ''),
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

/**
 * Normaliza la columna Date del CSV a YYYY-MM-DD.
 * Lensware puede exportar: YYYYMMDD, YYYY-MM-DD, DD/MM/YYYY, M/D/YYYY, DD-MM-YYYY, etc.
 */
function normalizeRecordDate(string $dateRaw): ?string {
    $d = trim($dateRaw);
    if ($d === '') return null;

    // YYYY-MM-DD o YYYY-MM-DD HH:MM:SS
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }

    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $d, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        $year = (int)$m[3];
        // Si uno de los valores es > 12, es el día
        if ($a > 12 && $b <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $b, $a);
        }
        if ($b > 12 && $a <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $a, $b);
        }
        // Ambiguo: Costa Rica usa DD/MM/YYYY
        return sprintf('%04d-%02d-%02d', $year, $b, $a);
    }

    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})$/', $d, $m)) {
        $year = (int)$m[3] + ($m[3] < 70 ? 2000 : 1900);
        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($a > 12 && $b <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $b, $a);
        }
        if ($b > 12 && $a <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $a, $b);
        }
        return sprintf('%04d-%02d-%02d', $year, $b, $a);
    }

    $ts = strtotime($d);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return null;
}

/** Extrae la hora (0-23) de la columna Time del CSV. */
/** Texto para columna Blank description (consolida blank + columnas movidas). */
function formatBlankDescription(array $r, bool $includeDevice = false, bool $includeCode = false): string {
    $parts = [];
    if (!empty(trim($r['blank_desc'] ?? ''))) $parts[] = trim($r['blank_desc']);
    if ($includeDevice && !empty(trim($r['device'] ?? ''))) $parts[] = trim($r['device']);
    if ($includeCode && !empty(trim($r['reason'] ?? ''))) $parts[] = 'Cód. ' . trim($r['reason']);
    return $parts ? implode(' · ', $parts) : '';
}

function recordHour(string $timeRaw): int {
    $t = trim($timeRaw);
    if (preg_match('/^(\d{1,2}):/', $t, $m)) {
        $h = (int)$m[1];
        return ($h >= 0 && $h <= 23) ? $h : 0;
    }
    return (int)substr($t, 0, 2);
}

function calculateStats(array $records): array {
    $total = count($records);
    $byStatus = []; $byHour = array_fill(0, 24, 0);
    $byDevice = []; $byUser = []; $byCause = [];
    $jobsSet = []; $jobsBrea = []; $breaPerJob = []; $breaDev = []; $breaUser = [];
    $totalLentesBrea = 0; // conteo real de lentes (filas) con BREA
    // Para causas: deduplicar por orden única (mismo job+causa = 1 evento de orden)
    $breaJobCausaSeen = []; // "job|causa" => true

    foreach ($records as $r) {
        $s = $r['status'];
        $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;

        $hour = recordHour($r['time_raw']);
        if ($hour >= 0 && $hour < 24) $byHour[$hour]++;

        $dev = trim($r['device'] ?? '');
        if ($dev !== '') $byDevice[$dev] = ($byDevice[$dev] ?? 0) + 1;

        $usr = $r['user'] ?: 'Desconocido';
        $byUser[$usr] = ($byUser[$usr] ?? 0) + 1;

        $jobsSet[$r['job']] = true;

        if ($r['is_breakage']) {
            $totalLentesBrea++; // cada fila BREA = 1 lente quebrado
            $jobsBrea[$r['job']] = true;
            $cause = $r['reason_descr'] ?: ($r['reason'] ?: 'Sin causa');

            // Causa por orden única: si el mismo job ya tiene esta causa, no duplicar
            $causeKey = $r['job'] . '|' . $cause;
            if (!isset($breaJobCausaSeen[$causeKey])) {
                $breaJobCausaSeen[$causeKey] = true;
                $byCause[$cause] = ($byCause[$cause] ?? 0) + 1;
                // Top jobs brea: contar por órdenes únicas (no por lentes)
                $breaPerJob[$r['job']] = ($breaPerJob[$r['job']] ?? 0) + 1;
            }

            if ($dev !== '') $breaDev[$dev] = ($breaDev[$dev] ?? 0) + 1;
            $breaUser[$usr]  = ($breaUser[$usr]  ?? 0) + 1;
        }
    }

    arsort($byDevice); arsort($byUser); arsort($byCause);
    arsort($breaPerJob);

    $topJobsBrea = [];
    foreach (array_slice($breaPerJob, 0, 10, true) as $job => $count) {
        $topJobsBrea[] = ['job' => $job, 'count' => $count];
    }

    $jobsUnicos  = count($jobsSet);
    $jobsConBrea = count($jobsBrea); // órdenes únicas con quiebra

    return [
        'total'              => $total,
        'jobs_unicos'        => $jobsUnicos,
        'jobs_con_brea'      => $jobsConBrea,       // KPI: órdenes únicas con quiebra
        'total_lentes_brea'  => $totalLentesBrea,   // KPI: total lentes quebrados (filas BREA)
        'brea_tasa'          => $jobsUnicos > 0 ? round($jobsConBrea / $jobsUnicos * 100, 2) : 0,
        'eventos_brea'       => $totalLentesBrea,   // alias para compatibilidad
        'usuarios'           => count($byUser),
        'dispositivos'       => count($byDevice),
        'lentes_tipos'       => count(array_unique(array_column($records, 'lens_desc'))),
        'por_status'         => $byStatus,
        'por_hora'           => $byHour,
        'por_device'         => $byDevice,
        'por_user'           => $byUser,
        'brea_causa'         => $byCause,           // causas por órdenes únicas
        'brea_device'        => $breaDev,
        'brea_por_user'      => $breaUser,
        'top_jobs_brea'      => $topJobsBrea,
    ];
}

/**
 * Devuelve quiebras unicas por orden.
 * Si un Job tiene R y L ambos en BREA, se consolidan en una sola fila con side_label = 'OD+OI'.
 */
function getBreakages(array $records): array {
    $breaRecords = array_filter($records, fn($r) => $r['is_breakage']);

    // Agrupar por job
    $byJob = [];
    foreach ($breaRecords as $r) {
        $byJob[$r['job']][] = $r;
    }

    $consolidated = [];
    foreach ($byJob as $job => $rows) {
        if (count($rows) === 1) {
            $consolidated[] = $rows[0];
        } else {
            // Multiples filas: consolidar lados
            $base  = $rows[0];
            $sides = array_unique(array_map(fn($r) => $r['side'], $rows));
            sort($sides);
            if (in_array('R', $sides) && in_array('L', $sides)) {
                $base['side']       = 'RL';
                $base['side_label'] = 'OD+OI';
            } else {
                $base['side']       = implode('+', $sides);
                $base['side_label'] = implode('+', array_map(
                    fn($s) => match($s) { 'R' => 'OD', 'L' => 'OI', default => $s },
                    $sides
                ));
            }
            $consolidated[] = $base;
        }
    }

    // Ordenar por fecha+hora descendente
    usort($consolidated, function ($a, $b) {
        $da = ($a['date_raw'] ?? '') . ' ' . ($a['time_raw'] ?? '');
        $db = ($b['date_raw'] ?? '') . ' ' . ($b['time_raw'] ?? '');
        return strcmp($db, $da);
    });

    return array_values($consolidated);
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
        $hour = recordHour($r['time_raw']);
        if ($hour >= 0 && $hour < 24) $hourDist[$hour]++;
        if (!isset($jobs[$r['job']])) $jobs[$r['job']] = ['total' => 0, 'brea' => 0];
        $jobs[$r['job']]['total']++;
        if ($r['is_breakage']) { $jobs[$r['job']]['brea']++; $brea++; }
    }
    arsort($jobs);
    return [
        'records'           => $filtered,
        'total_records'     => count($filtered),
        'total_jobs'        => count($jobs),
        'breakages'         => $brea,
        'hour_distribution' => $hourDist,
        'jobs'              => $jobs,
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

    $lastBackupTs = getLastBackupTimestamp();
    if ($csvMts > $lastBackupTs) {
        backupCSV($filepath);
        saveLastBackupTimestamp($csvMts);
    }

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
            'is_daily' => str_contains(basename($f), '_2359_'),
        ];
    }
    usort($list, fn($a, $b) => strcmp($b['modified'], $a['modified']));
    return $list;
}

// ─────────────────────────────────────────────────────────────────────────────
// Búsqueda de Job en vivo + backups históricos (un backup oficial por día)
// ─────────────────────────────────────────────────────────────────────────────
function recordSortKey(array $r): int {
    $d = normalizeRecordDate($r['date_raw'] ?? '') ?? '1970-01-01';
    $t = trim($r['time_raw'] ?? '00:00:00');
    if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
        return (int) strtotime("{$d} {$m[1]}:{$m[2]}:00");
    }
    return (int) strtotime($d);
}

function recordSignature(array $r): string {
    return implode('|', [
        $r['job'] ?? '',
        $r['date_raw'] ?? '',
        $r['time_raw'] ?? '',
        $r['status'] ?? '',
    ]);
}

function filterRecordsByJob(array $records, string $jobQuery): array {
    $q = trim($jobQuery);
    if ($q === '') return [];
    return array_values(array_filter($records, function ($r) use ($q) {
        $job = (string)($r['job'] ?? '');
        return $job === $q || stripos($job, $q) !== false;
    }));
}

function sortRecordsNewestFirst(array $records): array {
    usort($records, fn($a, $b) => recordSortKey($b) <=> recordSortKey($a));
    return $records;
}

/** Lista de backups oficiales por día para búsqueda (excluye hoy: se usa caché en vivo). */
function getHistoricalBackupsForSearch(): array {
    $all = listBackups();
    $byDate = [];
    foreach ($all as $b) {
        $name = $b['filename'];
        if (preg_match('/BACKUP_(\d{4})(\d{2})(\d{2})_/', $name, $m)) {
            $dateKey = "{$m[1]}-{$m[2]}-{$m[3]}";
        } else {
            $dateKey = substr($b['modified'], 0, 10);
        }
        $byDate[$dateKey][] = $b;
    }
    krsort($byDate);

    $today = (new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica')))->format('Y-m-d');
    $out   = [];

    foreach ($byDate as $date => $backups) {
        if ($date === $today) {
            continue;
        }
        $daily = null;
        foreach ($backups as $b) {
            if (str_contains($b['filename'], '_2359_')) {
                $daily = $b;
                break;
            }
        }
        $chosen = $daily ?? $backups[0];
        $out[]  = [
            'date'     => $date,
            'label'    => date('d/m/Y', strtotime($date)),
            'filename' => $chosen['filename'],
            'is_daily' => $daily !== null,
        ];
    }

    return $out;
}

function collectLiveRecordsForSearch(): array {
    $cache = readCache();
    if ($cache && !empty($cache['records'])) {
        return $cache['records'];
    }
    $latest = findLatestCSV();
    if (!$latest) {
        return [];
    }
    $data = processCSV($latest);
    return $data['records'] ?? [];
}

function searchJobHistory(string $jobQuery): array {
    $jobQuery = trim($jobQuery);
    $seen     = [];
    $sources  = [];
    $total    = 0;

    // 1) Datos en vivo / caché actual
    $liveRecords = sortRecordsNewestFirst(filterRecordsByJob(collectLiveRecordsForSearch(), $jobQuery));
    if (!empty($liveRecords)) {
        foreach ($liveRecords as $r) {
            $seen[recordSignature($r)] = true;
        }
        $sources[] = [
            'id'       => 'live',
            'label'    => 'En vivo (datos actuales)',
            'date'     => (new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica')))->format('Y-m-d'),
            'filename' => null,
            'is_live'  => true,
            'records'  => $liveRecords,
        ];
        $total += count($liveRecords);
    }

    // 2) Backups históricos (un archivo por día)
    foreach (getHistoricalBackupsForSearch() as $meta) {
        $path = BACKUP_FOLDER . '/' . $meta['filename'];
        if (!file_exists($path)) {
            continue;
        }

        $data    = processCSV($path);
        $matches = $data ? filterRecordsByJob($data['records'] ?? [], $jobQuery) : [];
        if (empty($matches)) {
            continue;
        }

        $unique = [];
        foreach ($matches as $r) {
            $sig = recordSignature($r);
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $unique[]   = $r;
        }

        if (empty($unique)) {
            continue;
        }

        $unique = sortRecordsNewestFirst($unique);
        $label  = $meta['label'] . ($meta['is_daily'] ? ' · backup diario 23:59' : ' · backup');

        $sources[] = [
            'id'       => 'backup_' . $meta['date'],
            'label'    => $label,
            'date'     => $meta['date'],
            'filename' => $meta['filename'],
            'is_live'  => false,
            'records'  => $unique,
        ];
        $total += count($unique);
    }

    return [
        'job_query'       => $jobQuery,
        'sources'         => $sources,
        'total_records'   => $total,
        'sources_count'   => count($sources),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// cleanupOldBackups:
// - Conserva TODOS los backups _2359_ (uno por día = el oficial)
// - Borra todos los intermedios (sin _2359_ en el nombre)
// - Retorna resumen de lo que borró
// ─────────────────────────────────────────────────────────────────────────────
function cleanupOldBackups(): array {
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) return ['deleted' => 0, 'kept' => 0, 'files_deleted' => []];

    $files   = glob($dir . '/BACKUP_*.csv') ?: [];
    $deleted = [];
    $kept    = 0;

    foreach ($files as $f) {
        $name = basename($f);
        if (str_contains($name, '_2359_')) {
            $kept++;
            continue;
        }
        if (@unlink($f)) {
            $deleted[] = $name;
            logMessage("Backup intermedio eliminado: $name");
        } else {
            logMessage("No se pudo eliminar: $name", 'error');
        }
    }

    return [
        'deleted'       => count($deleted),
        'kept'          => $kept,
        'files_deleted' => $deleted,
    ];
}