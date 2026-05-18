<?php
// includes/functions.php - Funciones principales (Railway-ready)
// CORREGIDO: Unifica el conteo de quiebras por ÓRDENES ÚNICAS

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

/** Lentes por fila: R/OD=1, L/OI=1, R/L u OD+OI=2. */
function lensCountFromSide(string $side): int {
    $n = strtoupper(str_replace([' ', '\\'], '', trim($side)));
    if ($n === '') {
        return 1;
    }
    if (in_array($n, ['R/L', 'RL', 'OD+OI', 'OI+OD', 'BINO', 'BOTH'], true)) {
        return 2;
    }
    if (str_contains($n, 'OD') && str_contains($n, 'OI')) {
        return 2;
    }
    if (str_contains($n, '+') && str_contains($n, 'R') && str_contains($n, 'L')) {
        return 2;
    }
    if (in_array($n, ['R', 'L', 'OD', 'OI'], true)) {
        return 1;
    }
    return 1;
}

function lensCountFromRecord(array $r): int {
    return lensCountFromSide($r['side_label'] ?? $r['side'] ?? '');
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

    // 🔧 Usar la función CORREGIDA que cuenta órdenes únicas
    $records = $data['records'];
    
    return [
        'records'       => $records,
        'stats'         => calculateStatsCorrected($records),  // ← FUNCIÓN CORREGIDA
        'breakages'     => getBreakagesConsolidated($records), // ← FUNCIÓN CORREGIDA
        'device_stats'  => getDeviceStats($records),
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

        $sideRaw = sanitizeCsvField($row['R/L'] ?? '');
        $sideLabel = match(strtoupper(str_replace([' ', '\\'], '', $sideRaw))) {
            'R', 'OD'   => 'OD',
            'L', 'OI'   => 'OI',
            'R/L', 'RL', 'OD+OI', 'OI+OD' => 'OD+OI',
            default => $sideRaw,
        };

        $records[] = [
            'job'          => $job,
            'status'       => $status,
            'status_label' => $STATUS_LABELS[$status] ?? $status,
            'is_breakage'  => ($status === 'BREA'),
            'date_raw'     => sanitizeCsvField($row['Date'] ?? ''),
            'time_raw'     => sanitizeCsvField($row['Time'] ?? ''),
            'user'         => sanitizeCsvField($row['User'] ?? ''),
            'device'       => sanitizeCsvField($row['Device'] ?? ''),
            'side'         => $sideRaw,
            'side_label'   => $sideLabel,
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
 */
function normalizeRecordDate(string $dateRaw): ?string {
    $d = trim($dateRaw);
    if ($d === '') return null;

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
        if ($a > 12 && $b <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $b, $a);
        }
        if ($b > 12 && $a <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $a, $b);
        }
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

// ══════════════════════════════════════════════════════════════════════════════
// FUNCIONES CORREGIDAS - Cuentan ÓRDENES ÚNICAS, no eventos
// ══════════════════════════════════════════════════════════════════════════════

/**
 * 🔧 CORREGIDA Y OPTIMIZADA: Calcula estadísticas contando INCIDENTES por momento específico
 * 
 * DIFERENCIAS CLAVE:
 * - Mismo job + misma fecha + misma hora:minuto = 1 incidente (consolida OD+OI)
 * - Mismo job + misma fecha + hora diferente = incidentes separados
 * - Mismo job + mismo lado + hora diferente = incidentes separados
 * 
 * RETORNA:
 * - jobs_con_brea: número de INCIDENTES (eventos de quiebra en el tiempo)
 * - jobs_unicos_afectados: número de JOBS distintos que tuvieron al menos una quiebra
 * - total_lentes_brea: suma total de lentes quebrados (OD=1, OI=1, OD+OI=2)
 */
function calculateStatsCorrected(array $records): array {
    if (empty($records)) {
        return [
            'total' => 0,
            'jobs_unicos' => 0,
            'jobs_unicos_afectados' => 0,
            'jobs_con_brea' => 0,
            'total_lentes_brea' => 0,
            'brea_tasa' => 0,
            'por_status' => [],
            'por_hora' => array_fill(0, 24, 0),
            'por_device' => [],
            'brea_causa' => [],
            'usuarios' => 0,
            'dispositivos' => 0,
            'top_jobs_brea' => [],
        ];
    }

    $total = count($records);
    $porStatus = [];
    $porHora = array_fill(0, 24, 0);
    $porDevice = [];
    $usuarios = [];
    $dispositivos = [];
    
    // Tracking por JOB (orden única) - para jobs únicos afectados
    $jobsUnicos = [];
    $jobsUnicosAfectados = [];  // jobs que tienen al menos una quiebra
    
    // Tracking por INCIDENTE (job + fecha + hora:minuto)
    $incidentes = [];            // key => detalles del incidente
    $lentesPorIncidente = [];    // lentes por incidente
    $causasPorIncidente = [];    // causas por incidente
    
    foreach ($records as $r) {
        $status = $r['status'] ?? 'UNKNOWN';
        $porStatus[$status] = ($porStatus[$status] ?? 0) + 1;

        $hora = recordHour($r['time_raw'] ?? '00:00:00');
        if ($hora >= 0 && $hora < 24) $porHora[$hora]++;

        $device = $r['device'] ?? 'Desconocido';
        if ($device !== '') {
            $porDevice[$device] = ($porDevice[$device] ?? 0) + 1;
            $dispositivos[$device] = true;
        }

        $user = $r['user'] ?? 'Desconocido';
        if ($user !== '') $usuarios[$user] = true;

        $job = $r['job'];
        $jobsUnicos[$job] = true;

        // 🔧 QUIEBRA: contar por INCIDENTE (job + fecha + hora:minuto)
        if ($r['is_breakage']) {
            // Marcar este job como afectado
            $jobsUnicosAfectados[$job] = true;
            
            // Clave de incidente: job + fecha + hora:minuto (ignorar segundos)
            $timeKey = substr($r['time_raw'] ?? '00:00:00', 0, 5); // "HH:MM"
            $incidentKey = $job . '|' . ($r['date_raw'] ?? '') . '|' . $timeKey;
            
            // Contar lentes para este incidente
            $lensesThisEvent = lensCountFromSide($r['side_label'] ?? $r['side'] ?? '');
            $lentesPorIncidente[$incidentKey] = ($lentesPorIncidente[$incidentKey] ?? 0) + $lensesThisEvent;
            
            // Guardar detalles del incidente para la causa
            $causa = $r['reason_descr'] ?? ($r['reason'] ?? 'Sin especificar');
            if (!isset($causasPorIncidente[$incidentKey])) {
                $causasPorIncidente[$incidentKey] = [];
                // Guardar el registro base para referencia
                $incidentes[$incidentKey] = $r;
            }
            $causasPorIncidente[$incidentKey][$causa] = true;
        }
    }

    // Total lentes quebrados = suma de lentes por incidente
    $totalLentesBrea = array_sum($lentesPorIncidente);
    
    // Total incidentes = cantidad de claves únicas
    $totalIncidentes = count($incidentes);
    
    // Top jobs con más incidentes (por cantidad de incidentes, no por lentes)
    $topJobsBrea = [];
    $incidentesPorJob = [];
    foreach ($incidentes as $key => $incidente) {
        $job = $incidente['job'];
        $incidentesPorJob[$job] = ($incidentesPorJob[$job] ?? 0) + 1;
    }
    foreach ($incidentesPorJob as $job => $count) {
        $topJobsBrea[] = ['job' => $job, 'count' => $count];
    }
    usort($topJobsBrea, fn($a, $b) => $b['count'] - $a['count']);
    $topJobsBrea = array_slice($topJobsBrea, 0, 10);
    
    // Causas: contar cada incidente UNA VEZ por causa
    $breaCausa = [];
    foreach ($causasPorIncidente as $causas) {
        foreach ($causas as $causa => $dummy) {
            $breaCausa[$causa] = ($breaCausa[$causa] ?? 0) + 1;
        }
    }
    arsort($breaCausa);
    
    $totalJobs = count($jobsUnicos);
    $totalJobsAfectados = count($jobsUnicosAfectados);
    $breaTasa = $totalJobs > 0 ? ($totalJobsAfectados / $totalJobs) * 100 : 0;

    return [
        'total'                   => $total,
        'jobs_unicos'             => $totalJobs,
        'jobs_unicos_afectados'   => $totalJobsAfectados,  // JOBS distintos con quiebra
        'jobs_con_brea'           => $totalIncidentes,      // INCIDENTES (eventos en el tiempo)
        'total_lentes_brea'       => $totalLentesBrea,      // Lentes físicos quebrados
        'brea_tasa'               => round($breaTasa, 2),
        'usuarios'                => count($usuarios),
        'dispositivos'            => count($dispositivos),
        'por_status'              => $porStatus,
        'por_hora'                => $porHora,
        'por_device'              => $porDevice,
        'brea_causa'              => $breaCausa,
        'top_jobs_brea'           => $topJobsBrea,
    ];
}

/**
 * 🔧 CORREGIDA: Obtiene quiebras como INCIDENTES (no consolida diferentes momentos)
 * 
 * - Mismo job + misma fecha + misma hora:minuto = 1 fila (consolida OD+OI)
 * - Mismo job + misma fecha + hora diferente = filas separadas
 */
function getBreakagesConsolidated(array $records): array {
    // Agrupar por job + fecha + hora:minuto (ignorar segundos)
    $grouped = [];
    foreach ($records as $r) {
        if (!$r['is_breakage']) continue;
        
        // Usar hora:minuto para agrupar (mismo momento exacto)
        $timeKey = substr($r['time_raw'] ?? '00:00:00', 0, 5); // "HH:MM"
        $key = $r['job'] . '|' . ($r['date_raw'] ?? '') . '|' . $timeKey;
        
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = $r;
    }
    
    $breakages = [];
    foreach ($grouped as $rows) {
        if (count($rows) === 1) {
            $breakages[] = $rows[0];
        } else {
            // Mismo momento: consolidar OD + OI en una sola fila
            $breakages[] = mergeBreakageRecords($rows);
        }
    }
    
    // Ordenar por fecha+hora descendente (más reciente primero)
    usort($breakages, function($a, $b) {
        $da = ($a['date_raw'] ?? '') . ' ' . ($a['time_raw'] ?? '');
        $db = ($b['date_raw'] ?? '') . ' ' . ($b['time_raw'] ?? '');
        return strcmp($db, $da);
    });
    
    return $breakages;
}

/**
 * 🔧 CORREGIDA: Consolida múltiples registros de quiebra del MISMO MOMENTO (OD + OI)
 */
function mergeBreakageRecords(array $records): array {
    if (empty($records)) return [];
    if (count($records) === 1) return $records[0];
    
    $base = $records[0];
    $hasR = false;
    $hasL = false;
    $reasons = [];
    
    foreach ($records as $r) {
        $side = $r['side_label'] ?? $r['side'] ?? '';
        
        if (in_array($side, ['OD', 'R'])) $hasR = true;
        if (in_array($side, ['OI', 'L'])) $hasL = true;
        
        $reason = $r['reason_descr'] ?? '';
        if ($reason) $reasons[$reason] = true;
    }
    
    // Determinar side_label consolidado
    if ($hasR && $hasL) {
        $base['side_label'] = 'OD+OI';
        $base['side'] = 'R/L';
    } elseif ($hasR) {
        $base['side_label'] = 'OD';
        $base['side'] = 'R';
    } elseif ($hasL) {
        $base['side_label'] = 'OI';
        $base['side'] = 'L';
    }
    
    // Consolidar razón (si hay múltiples causas en el mismo momento)
    if (count($reasons) > 1) {
        $base['reason_descr'] = implode(' + ', array_keys($reasons));
    } else {
        $base['reason_descr'] = array_key_first($reasons) ?: ($base['reason_descr'] ?? 'Sin especificar');
    }
    
    return $base;
}

// ══════════════════════════════════════════════════════════════════════════════
// FUNCIONES LEGACY (mantenidas por compatibilidad, pero NO recomendadas)
// ══════════════════════════════════════════════════════════════════════════════

/**
 * @deprecated Usar calculateStatsCorrected() en su lugar
 */
function calculateStats(array $records): array {
    return calculateStatsCorrected($records);
}

/**
 * @deprecated Usar getBreakagesConsolidated() en su lugar
 */
function getBreakages(array $records): array {
    return getBreakagesConsolidated($records);
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
// Histórico por rango de fechas (múltiples backups)
// ─────────────────────────────────────────────────────────────────────────────

function appTodayDate(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica')))->format('Y-m-d');
}

function groupBackupsByDateFromList(array $all): array {
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
    return $byDate;
}

function pickOfficialBackupMeta(array $backups, bool $isToday): ?array {
    if ($backups === []) return null;
    if ($isToday) return $backups[0];
    foreach ($backups as $b) {
        if (!empty($b['is_daily']) || str_contains($b['filename'], '_2359_')) {
            return $b;
        }
    }
    return $backups[0];
}

function filterRecordsByDateRange(array $records, string $dateFrom, string $dateTo): array {
    return array_values(array_filter($records, function ($r) use ($dateFrom, $dateTo) {
        $d = normalizeRecordDate($r['date_raw'] ?? '');
        return $d !== null && $d >= $dateFrom && $d <= $dateTo;
    }));
}

function filterRecordsByHourRange(array $records, ?int $hourFrom, ?int $hourTo): array {
    if ($hourFrom === null && $hourTo === null) {
        return $records;
    }
    $from = $hourFrom ?? 0;
    $to   = $hourTo ?? 23;
    return array_values(array_filter($records, function ($r) use ($from, $to) {
        $h = recordHour($r['time_raw'] ?? '');
        return $h >= $from && $h <= $to;
    }));
}

function mergeRecordsDeduped(array $records): array {
    $seen = [];
    $out  = [];
    foreach ($records as $r) {
        $sig = recordSignature($r);
        if (isset($seen[$sig])) continue;
        $seen[$sig] = true;
        $out[] = $r;
    }
    return sortRecordsNewestFirst($out);
}

function recordSignature(array $r): string {
    return implode('|', [
        $r['job'] ?? '',
        $r['date_raw'] ?? '',
        $r['time_raw'] ?? '',
        $r['status'] ?? '',
        $r['side'] ?? '',
    ]);
}

function sortRecordsNewestFirst(array $records): array {
    usort($records, function($a, $b) {
        $da = ($a['date_raw'] ?? '') . ' ' . ($a['time_raw'] ?? '');
        $db = ($b['date_raw'] ?? '') . ' ' . ($b['time_raw'] ?? '');
        return strcmp($db, $da);
    });
    return $records;
}

function miniStatsForRecords(array $records): array {
    $s = calculateStatsCorrected($records);
    return [
        'total'             => $s['total'],
        'jobs_unicos'       => $s['jobs_unicos'],
        'jobs_con_brea'     => $s['jobs_con_brea'],
        'total_lentes_brea' => $s['total_lentes_brea'],
        'brea_tasa'         => $s['brea_tasa'],
    ];
}

function buildBackupRangePayload(string $dateFrom, string $dateTo, string $hourFrom = '', string $hourTo = ''): ?array {
    $tz    = new DateTimeZone('America/Costa_Rica');
    $from  = DateTimeImmutable::createFromFormat('Y-m-d', $dateFrom, $tz);
    $to    = DateTimeImmutable::createFromFormat('Y-m-d', $dateTo, $tz);
    if (!$from || !$to || $from > $to) return null;

    $days = (int) $from->diff($to)->days + 1;
    if ($days > BACKUP_RANGE_MAX_DAYS) {
        return ['_error' => 'max_days', 'max_days' => BACKUP_RANGE_MAX_DAYS, 'requested' => $days];
    }

    $today   = appTodayDate();
    $byDate  = groupBackupsByDateFromList(listBackups());
    $merged  = [];
    $filesLoaded = [];
    $statsByDay  = [];

    for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
        $dateKey = $cursor->format('Y-m-d');
        $isToday = ($dateKey === $today);
        $dayRecords = [];

        if ($isToday) {
            $live = collectLiveRecordsForSearch();
            $dayRecords = filterRecordsByDateRange($live, $dateKey, $dateKey);
            if ($dayRecords !== []) {
                $filesLoaded[] = ['date' => $dateKey, 'source' => 'live', 'filename' => null, 'records' => count($dayRecords)];
            }
        } else {
            $backups = $byDate[$dateKey] ?? [];
            $chosen  = pickOfficialBackupMeta($backups, false);
            if (!$chosen) continue;
            $path = BACKUP_FOLDER . '/' . $chosen['filename'];
            if (!is_file($path)) continue;
            $data = processCSV($path);
            if (!$data || empty($data['records'])) continue;
            $dayRecords = filterRecordsByDateRange($data['records'], $dateKey, $dateKey);
            $filesLoaded[] = ['date' => $dateKey, 'source' => 'backup', 'filename' => $chosen['filename'], 'records' => count($dayRecords)];
        }

        if ($dayRecords !== []) {
            $statsByDay[$dateKey] = miniStatsForRecords($dayRecords);
            $merged = array_merge($merged, $dayRecords);
        }
    }

    $hourFromInt = $hourFrom !== '' ? (int) $hourFrom : null;
    $hourToInt   = $hourTo !== '' ? (int) $hourTo : null;
    $merged      = filterRecordsByHourRange($merged, $hourFromInt, $hourToInt);

    if ($merged === []) return null;

    $deduped           = mergeRecordsDeduped($merged);
    $duplicatesRemoved = count($merged) - count($deduped);

    return [
        'records'      => $deduped,
        'stats'        => calculateStatsCorrected($deduped),
        'breakages'    => getBreakagesConsolidated($deduped),
        'device_stats' => getDeviceStats($deduped),
        'filename'     => "RANGO_{$dateFrom}_a_{$dateTo}",
        'source'       => 'backup_range',
        'filters'      => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'hour_from' => $hourFrom, 'hour_to' => $hourTo],
        'range_meta'   => [
            'date_from'           => $dateFrom,
            'date_to'             => $dateTo,
            'days_in_range'       => $days,
            'days_with_data'      => count($statsByDay),
            'files_loaded'        => $filesLoaded,
            'records_before_dedup'=> count($merged),
            'records_after_dedup' => count($deduped),
            'duplicates_removed'  => $duplicatesRemoved,
        ],
        'stats_by_day' => $statsByDay,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Búsqueda de Job en vivo + backups históricos
// ─────────────────────────────────────────────────────────────────────────────

function filterRecordsByJob(array $records, string $jobQuery): array {
    $q = trim($jobQuery);
    if ($q === '') return [];
    return array_values(array_filter($records, function ($r) use ($q) {
        $job = (string)($r['job'] ?? '');
        return $job === $q || stripos($job, $q) !== false;
    }));
}

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
        if ($date === $today) continue;
        $daily = null;
        foreach ($backups as $b) {
            if (str_contains($b['filename'], '_2359_')) {
                $daily = $b;
                break;
            }
        }
        $chosen = $daily ?? $backups[0];
        $out[] = [
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
    if (!$latest) return [];
    $data = processCSV($latest);
    return $data['records'] ?? [];
}

function searchJobHistory(string $jobQuery): array {
    $jobQuery = trim($jobQuery);
    $seen     = [];
    $sources  = [];
    $total    = 0;

    // Datos en vivo
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

    // Backups históricos
    foreach (getHistoricalBackupsForSearch() as $meta) {
        $path = BACKUP_FOLDER . '/' . $meta['filename'];
        if (!file_exists($path)) continue;

        $data    = processCSV($path);
        $matches = $data ? filterRecordsByJob($data['records'] ?? [], $jobQuery) : [];
        if (empty($matches)) continue;

        $unique = [];
        foreach ($matches as $r) {
            $sig = recordSignature($r);
            if (isset($seen[$sig])) continue;
            $seen[$sig] = true;
            $unique[] = $r;
        }
        if (empty($unique)) continue;

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

function logMessage(string $message, string $level = 'info'): void {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
    $logFile = $logDir . '/app.log';
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] [$level] $message" . PHP_EOL;
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}