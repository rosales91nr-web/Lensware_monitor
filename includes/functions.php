<?php
// includes/functions.php - Funciones principales (Railway-ready)
// VERSIÓN FINAL CORREGIDA - Maneja múltiples quiebras por orden/distintas horas

require_once __DIR__ . '/../config.php';

// =============================================================================
// FUNCIONES DE LIMPIEZA Y NORMALIZACIÓN DE TEXTO
// =============================================================================

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

// =============================================================================
// FUNCIONES DE CONTEO DE LENTES
// =============================================================================

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

// =============================================================================
// FUNCIONES DE BÚSQUEDA DE ARCHIVOS
// =============================================================================

/** Detecta si una ruta es UNC/SMB (\\servidor\...) — nunca accesible desde Railway/Linux. */
function isUncPath(string $path): bool {
    return str_starts_with($path, '\\\\') || str_starts_with($path, '//');
}

/**
 * Verifica si WATCH_FOLDER es accesible SIN bloquear.
 * En Railway WATCH_FOLDER es ruta SMB — retorna false inmediatamente.
 */
function isWatchFolderAccessible(): bool {
    $folder = WATCH_FOLDER;
    if (isUncPath($folder)) return false;
    if (!is_dir($folder))   return false;
    return is_readable($folder);
}

function findLatestCSV(): ?string {
    $folders = [];

    // WATCH_FOLDER solo si NO es ruta UNC/SMB y es accesible localmente
    if (!isUncPath(WATCH_FOLDER) && is_dir(WATCH_FOLDER) && is_readable(WATCH_FOLDER)) {
        $folders[] = WATCH_FOLDER;
    }

    // STAGING_FOLDER siempre (es local en Railway: /tmp/lensware/staging)
    if (defined('STAGING_FOLDER') && STAGING_FOLDER && STAGING_FOLDER !== WATCH_FOLDER) {
        if (is_dir(STAGING_FOLDER)) {
            $folders[] = STAGING_FOLDER;
        }
    }

    $latest   = null;
    $latestTs = 0;
    foreach ($folders as $folder) {
        foreach (CSV_PREFIXES as $prefix) {
            foreach (array_merge(
                glob($folder . '/' . $prefix . '*.csv') ?: [],
                glob($folder . '/' . $prefix . '*.CSV') ?: []
            ) as $file) {
                $ts = @filemtime($file);
                if ($ts && $ts > $latestTs) {
                    $latestTs = $ts;
                    $latest   = $file;
                }
            }
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
    while (preg_match('/^BACKUP_\d{8}(?:_\d{4,6})?_(.+)$/i', $name, $m)) {
        $name = $m[1];
    }
    return $name;
}

// =============================================================================
// PROCESAMIENTO DE CSV Y CONSTRUCCIÓN DE PAYLOAD
// =============================================================================

/** Construye el payload del dashboard (registros, stats, quiebras, etc.). */
function buildLiveDataPayload(string $filepath): ?array {
    if (!file_exists($filepath)) return null;

    $data = processCSV($filepath);
    if (!$data || empty($data['records'])) return null;

    $records = $data['records'];
    
    return [
        'records'       => $records,
        'stats'         => calculateStatsCorrected($records),
        'breakages'     => getBreakagesConsolidated($records),
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

    // Leer solo la primera línea para detectar delimitador
    $handle = fopen($filepath, 'r');
    if (!$handle) {
        logMessage("No se pudo abrir el archivo: $filepath", 'error');
        return null;
    }

    // Leer cabecera (quitando BOM si existe)
    $headerRaw = fgets($handle);
    if ($headerRaw === false) {
        fclose($handle);
        logMessage("Archivo vacío o sin cabecera: $filepath", 'error');
        return null;
    }
    $headerRaw = ltrim($headerRaw, "\xEF\xBB\xBF");
    $headerNorm = normalizeCsvEncoding(rtrim($headerRaw, "\r\n"));

    // Detectar delimitador
    $delimiters = ["\t", ";", ",", "|"];
    $delimiter = "\t";
    $maxCount = 0;
    foreach ($delimiters as $d) {
        $count = substr_count($headerNorm, $d);
        if ($count > $maxCount) {
            $maxCount = $count;
            $delimiter = $d;
        }
    }
    if ($maxCount === 0) {
        fclose($handle);
        logMessage("No se pudo detectar el delimitador en: $filepath", 'error');
        return null;
    }

    // Parsear cabecera
    $header = array_map('trim', str_getcsv($headerNorm, $delimiter));
    if (end($header) === '') array_pop($header);
    $headerCount = count($header);

    $requiredCols = ['Job', 'Status', 'Date', 'Time'];
    $missingCols = array_diff($requiredCols, $header);
    if (!empty($missingCols)) {
        fclose($handle);
        logMessage("Columnas requeridas faltantes: " . json_encode(array_values($missingCols)), 'error');
        return null;
    }

    global $STATUS_LABELS;
    $records = [];
    $lineNum = 0;

    // Leer línea por línea
    while (($line = fgets($handle)) !== false) {
        $lineNum++;
        $line = normalizeCsvEncoding(rtrim($line, "\r\n"));
        $line = rtrim($line, ';');
        if (trim($line) === '') continue;

        $cols = str_getcsv($line, $delimiter);
        
        if (count($cols) > $headerCount) {
            $cols = array_slice($cols, 0, $headerCount);
        } elseif (count($cols) < $headerCount) {
            $cols = array_pad($cols, $headerCount, '');
        }

        $row = array_combine($header, $cols);
        if (!$row) continue;

        $job = sanitizeCsvField($row['Job'] ?? '');
        if ($job === '') continue;

        $status = sanitizeCsvField($row['Status'] ?? '');
        
        $sideRaw = sanitizeCsvField($row['R/L'] ?? '');
        $sideLabel = match(strtoupper(str_replace([' ', '\\'], '', $sideRaw))) {
            'R', 'OD' => 'OD',
            'L', 'OI' => 'OI',
            'R/L', 'RL', 'OD+OI', 'OI+OD' => 'OD+OI',
            default => $sideRaw,
        };

        $records[] = [
            'job' => $job,
            'status' => $status,
            'status_label' => $STATUS_LABELS[$status] ?? $status,
            'is_breakage' => ($status === 'BREA'),
            'date_raw' => sanitizeCsvField($row['Date'] ?? ''),
            'time_raw' => sanitizeCsvField($row['Time'] ?? ''),
            'user' => sanitizeCsvField($row['User'] ?? ''),
            'device' => sanitizeCsvField($row['Device'] ?? ''),
            'side' => $sideRaw,
            'side_label' => $sideLabel,
            'lens_desc' => sanitizeCsvField($row['Lens description'] ?? ''),
            'blank_desc' => sanitizeCsvField($row['Blank description'] ?? ''),
            'reason' => sanitizeCsvField($row['Reason'] ?? ''),
            'reason_descr' => sanitizeCsvField($row['Reason Descr'] ?? ''),
            'dep' => sanitizeCsvField($row['Dep BR/RM'] ?? ''),
            'text' => sanitizeCsvField($row['Text'] ?? ''),
            'batch_info' => sanitizeCsvField($row['Batch/Info'] ?? ''),
            'sg' => sanitizeCsvField($row['Sg'] ?? ''),
            'option' => sanitizeCsvField($row['Option'] ?? ''),
            'type' => sanitizeCsvField($row['Type'] ?? ''),
            'dm' => sanitizeCsvField($row['DM'] ?? ''),
            'bcrv' => sanitizeCsvField($row['Bcrv'] ?? ''),
            'index_val' => ($indexRaw = trim($row['Index'] ?? '')) !== '' ? (float) str_replace(',', '.', $indexRaw) : null,
        ];
    }

    fclose($handle);

    if (empty($records)) {
        logMessage("No se encontraron registros válidos en el archivo", 'error');
        return null;
    }

    logMessage("Procesados " . count($records) . " registros exitosamente");
    return ['records' => $records, 'filename' => basename($filepath)];
}

// =============================================================================
// FUNCIONES DE NORMALIZACIÓN DE FECHAS Y HORAS
// =============================================================================

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

// =============================================================================
// FUNCIONES CORREGIDAS - Cuentan INCIDENTES por momento específico
// =============================================================================

/**
 * 🔧 CORREGIDA: Calcula estadísticas contando INCIDENTES por momento específico
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
    
    // Para órdenes únicas (sin importar cuántas veces quebró)
    $jobsUnicos = [];
    $jobsUnicosAfectados = [];  // jobs que tienen al menos una quiebra
    
    // Para incidentes (cada evento de quiebra en el tiempo)
    $incidentes = [];
    $lentesPorIncidente = [];
    $causasPorIncidente = [];
    
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

        // 🔧 QUIEBRA: contar por INCIDENTE
        if ($r['is_breakage']) {
            // IMPORTANTE: Marcar este job como afectado (1 vez por job)
            $jobsUnicosAfectados[$job] = true;
            
            // Clave de incidente: job + fecha + hora:minuto (ignorar segundos)
            $timeKey = substr($r['time_raw'] ?? '00:00:00', 0, 5);
            $incidentKey = $job . '|' . ($r['date_raw'] ?? '') . '|' . $timeKey;
            
            // Contar lentes para este incidente
            $lensesThisEvent = lensCountFromSide($r['side_label'] ?? $r['side'] ?? '');
            $lentesPorIncidente[$incidentKey] = ($lentesPorIncidente[$incidentKey] ?? 0) + $lensesThisEvent;
            
            // Guardar detalles del incidente para la causa
            if (!isset($causasPorIncidente[$incidentKey])) {
                $causasPorIncidente[$incidentKey] = [];
                $incidentes[$incidentKey] = $r;
            }
            $causa = $r['reason_descr'] ?? ($r['reason'] ?? 'Sin especificar');
            $causasPorIncidente[$incidentKey][$causa] = true;
        }
    }

    // ✅ CORREGIDO: 
    // - jobs_unicos_afectados = número de JOBS distintos que tuvieron quiebra
    // - jobs_con_brea = número de INCIDENTES (eventos de quiebra)
    $totalJobsAfectados = count($jobsUnicosAfectados);
    $totalIncidentes = count($incidentes);
    $totalLentesBrea = array_sum($lentesPorIncidente);
    
    // Top jobs con más incidentes
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
    $breaTasa = $totalJobs > 0 ? ($totalJobsAfectados / $totalJobs) * 100 : 0;

    return [
        'total'                   => $total,
        'jobs_unicos'             => $totalJobs,
        'jobs_unicos_afectados'   => $totalJobsAfectados,  // ← ÓRDENES ÚNICAS con quiebra
        'jobs_con_brea'           => $totalIncidentes,       // ← EVENTOS/INCIDENTES
        'total_lentes_brea'       => $totalLentesBrea,
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
    // Agrupar por job + fecha + hora:minuto
    $grouped = [];
    foreach ($records as $r) {
        if (!$r['is_breakage']) continue;
        
        $timeKey = substr($r['time_raw'] ?? '00:00:00', 0, 5);
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
            $breakages[] = mergeBreakageRecords($rows);
        }
    }
    
    // Ordenar por fecha+hora descendente
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

// =============================================================================
// FUNCIONES LEGACY (mantenidas por compatibilidad)
// =============================================================================

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
    $stats = [];
    $jobs = [];
    $hours = [];
    $jobsConBrea = [];
    $incidentes = [];

    foreach ($records as $r) {
        $dev = trim($r['device'] ?? '');
        if ($dev === '') continue;

        if (!isset($stats[$dev])) {
            $stats[$dev] = [
                'name' => $dev,
                'device' => $dev,
                'total' => 0,
                'jobs' => 0,
                'jobs_con_brea' => 0,
                'brea_eventos' => 0,
                'brea' => 0,
                'breakages' => 0,
                'rate' => 0,
            ];
            $jobs[$dev] = [];
            $hours[$dev] = [];
            $jobsConBrea[$dev] = [];
            $incidentes[$dev] = [];
        }

        $stats[$dev]['total']++;
        $jobs[$dev][$r['job']] = true;

        $hour = recordHour($r['time_raw'] ?? '');
        if ($hour >= 0 && $hour < 24) {
            $hours[$dev][$hour] = true;
        }

        if ($r['is_breakage']) {
            $stats[$dev]['brea']++;
            $stats[$dev]['breakages']++;
            $jobsConBrea[$dev][$r['job']] = true;
            $timeKey = substr($r['time_raw'] ?? '00:00:00', 0, 5);
            $incidentKey = $r['job'] . '|' . ($r['date_raw'] ?? '') . '|' . $timeKey;
            $incidentes[$dev][$incidentKey] = true;
        }
    }

    foreach ($stats as $dev => &$s) {
        $hoursWithProduction = count($hours[$dev]);
        $s['jobs'] = count($jobs[$dev]);
        $s['jobs_con_brea'] = count($jobsConBrea[$dev]);
        $s['brea_eventos'] = count($incidentes[$dev]);
        $s['rate'] = $s['jobs'] > 0 ? round($s['jobs_con_brea'] / $s['jobs'] * 100, 2) : 0;
        $s['avg_per_hour'] = $hoursWithProduction > 0 ? round($s['total'] / $hoursWithProduction, 2) : 0;
        $s['availability_percent'] = $hoursWithProduction > 0 ? round(min(100, $hoursWithProduction / 24 * 100), 2) : 0;
    }
    unset($s);

    usort($stats, fn($a, $b) => $b['total'] - $a['total']);
    return array_values($stats);
}

function getDeviceDetails(array $records, string $deviceName): array {
    $filtered = array_values(array_filter($records, fn($r) => $r['device'] === $deviceName));
    $hourDist = array_fill(0, 24, 0);
    $jobs = [];
    $jobsConBrea = [];
    $incidentes = [];

    foreach ($filtered as $r) {
        $hour = recordHour($r['time_raw']);
        if ($hour >= 0 && $hour < 24) $hourDist[$hour]++;

        if (!isset($jobs[$r['job']])) {
            $jobs[$r['job']] = ['total' => 0, 'brea' => 0, 'brea_eventos' => 0];
        }
        $jobs[$r['job']]['total']++;

        if ($r['is_breakage']) {
            $jobs[$r['job']]['brea']++;
            $jobsConBrea[$r['job']] = true;
            $timeKey = substr($r['time_raw'] ?? '00:00:00', 0, 5);
            $incidentKey = $r['job'] . '|' . ($r['date_raw'] ?? '') . '|' . $timeKey;
            if (!isset($incidentes[$incidentKey])) {
                $incidentes[$incidentKey] = true;
                $jobs[$r['job']]['brea_eventos']++;
            }
        }
    }

    arsort($jobs);
    
    $noProductionHours = [];
    for ($h = 0; $h < 24; $h++) {
        if ($hourDist[$h] === 0) {
            $noProductionHours[] = sprintf('%02d:00-%02d:59', $h, $h);
        }
    }
    
    $totalRecords = count($filtered);
    $hoursWithProduction = array_sum(array_map(fn($h) => $h > 0 ? 1 : 0, $hourDist));
    $avgPerHour = $hoursWithProduction > 0 ? round($totalRecords / $hoursWithProduction, 2) : 0;
    $availabilityPercent = $hoursWithProduction > 0 ? min(100, round($hoursWithProduction / 24 * 100, 2)) : 0;
    
    return [
        'records'               => $filtered,
        'total_records'         => $totalRecords,
        'total_jobs'            => count($jobs),
        'jobs_con_brea'         => count($jobsConBrea),
        'brea_eventos'          => count($incidentes),
        'breakages'             => count($incidentes),
        'hour_distribution'     => $hourDist,
        'jobs'                  => $jobs,
        'avg_per_hour'          => $avgPerHour,
        'availability_percent'  => $availabilityPercent,
        'no_production_hours'   => $noProductionHours,
    ];
}

// =============================================================================
// FUNCIONES DE CACHÉ
// =============================================================================

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

// =============================================================================
// FUNCIONES DE BACKUP
// =============================================================================

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

function ensureCSVBackups(string $filepath): array {
    if (!file_exists($filepath)) {
        return ['success' => false, 'error' => 'Archivo no existe'];
    }

    if (isBackupFile($filepath)) {
        return ['success' => true, 'dates' => [], 'created' => [], 'replaced' => [], 'error' => null];
    }

    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            logMessage("No se pudo crear BACKUP_FOLDER: $dir", 'error');
            return ['success' => false, 'error' => 'No se pudo crear BACKUP_FOLDER'];
        }
    }
    if (!is_writable($dir)) {
        logMessage("BACKUP_FOLDER no tiene permisos de escritura: $dir", 'error');
        return ['success' => false, 'error' => 'BACKUP_FOLDER sin permisos de escritura'];
    }

    $sync = syncProductionDateBackupsFromCsv($filepath);
    if (!$sync['success']) {
        if (function_exists('logMessage')) {
            logMessage('ensureCSVBackups: ' . ($sync['error'] ?? 'error desconocido'), 'error');
        }
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica'));
    if ($now->format('Hi') >= '2355' && $now->format('Hi') <= '2359') {
        finalizeDailyBackups($filepath);
    }

    return $sync;
}

function backupCSV(string $filepath, ?string $timestamp = null): bool {
    if (!file_exists($filepath)) {
        return false;
    }
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            logMessage("backupCSV: no se pudo crear directorio $dir", 'error');
            return false;
        }
    }
    $stamp = $timestamp ?? date('Ymd_His');
    $dest  = $dir . '/BACKUP_' . $stamp . '_' . basename($filepath);

    if (file_exists($dest)) {
        logMessage("Backup ya existe, omitido: " . basename($dest));
        return false;
    }

    if (!copy($filepath, $dest)) {
        logMessage("backupCSV: falló copy() hacia $dest", 'error');
        return false;
    }

    logMessage("Respaldo creado: " . basename($dest));
    return true;
}

function splitSourceCsvByProductionDate(string $filepath): ?array {
    if (!is_file($filepath)) {
        return null;
    }

    $raw = file_get_contents($filepath);
    if ($raw === false) {
        return null;
    }

    $raw = ltrim($raw, "\xEF\xBB\xBF");
    $lines = preg_split('/\r\n|\n|\r/', trim($raw));
    if (count($lines) < 2) {
        return null;
    }

    $delimiters = ["\t", ";", ",", "|"];
    $delimiter  = null;
    $maxCount   = 0;
    foreach ($delimiters as $d) {
        $count = substr_count($lines[0], $d);
        if ($count > $maxCount) {
            $maxCount  = $count;
            $delimiter = $d;
        }
    }
    if (!$delimiter) {
        return null;
    }

    $header = array_map('trim', str_getcsv($lines[0], $delimiter));
    if (end($header) === '') {
        array_pop($header);
    }

    $dateIdx = array_search('Date', $header, true);
    if ($dateIdx === false) {
        return null;
    }

    $byDate = [];
    for ($i = 1; $i < count($lines); $i++) {
        $line = rtrim($lines[$i], "\r\n;");
        if ($line === '') {
            continue;
        }
        $cols = str_getcsv($line, $delimiter);
        if (count($cols) <= $dateIdx) {
            continue;
        }
        $prodDate = normalizeRecordDate(trim($cols[$dateIdx] ?? ''));
        if ($prodDate === null) {
            continue;
        }
        $byDate[$prodDate][] = $line;
    }

    if ($byDate === []) {
        return null;
    }

    krsort($byDate);
    return [
        'header_line' => $lines[0],
        'delimiter'   => $delimiter,
        'by_date'     => $byDate,
    ];
}

function removeBackupsForProductionDate(string $productionDate): int {
    if (!is_dir(BACKUP_FOLDER)) {
        return 0;
    }
    $deleted = 0;
    $compact = str_replace('-', '', $productionDate);
    foreach (glob(BACKUP_FOLDER . '/BACKUP_' . $compact . '_*.csv') ?: [] as $file) {
        if (@unlink($file)) {
            $deleted++;
        }
    }
    return $deleted;
}

function writeDailyBackupCsv(string $destPath, string $headerLine, array $dataLines): bool {
    $content = "\xEF\xBB\xBF" . $headerLine . "\r\n" . implode("\r\n", $dataLines) . "\r\n";
    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return file_put_contents($destPath, $content, LOCK_EX) !== false;
}

function syncProductionDateBackupsFromCsv(string $sourcePath): array {
    $result = [
        'success'  => false,
        'created'  => [],
        'replaced' => [],
        'dates'    => [],
        'error'    => null,
    ];

    if (!is_file($sourcePath)) {
        $result['error'] = 'Archivo no encontrado';
        return $result;
    }

    if (!is_dir(BACKUP_FOLDER)) {
        @mkdir(BACKUP_FOLDER, 0777, true);
    }
    if (!is_writable(BACKUP_FOLDER)) {
        $result['error'] = 'Sin permisos de escritura en backups';
        return $result;
    }

    $split = splitSourceCsvByProductionDate($sourcePath);
    if (!$split) {
        $result['error'] = 'No se detectaron fechas de producción en el CSV';
        return $result;
    }

    $basename = basename($sourcePath);
    foreach ($split['by_date'] as $prodDate => $lines) {
        if ($lines === []) {
            continue;
        }

        $existing = glob(BACKUP_FOLDER . '/BACKUP_' . str_replace('-', '', $prodDate) . '_*.csv') ?: [];
        $hadBackup = count($existing) > 0;
        removeBackupsForProductionDate($prodDate);

        $compact  = str_replace('-', '', $prodDate);
        $destName = 'BACKUP_' . $compact . '_2359_' . $basename;
        $destPath = BACKUP_FOLDER . '/' . $destName;

        if (!writeDailyBackupCsv($destPath, $split['header_line'], $lines)) {
            logMessage("No se pudo escribir respaldo diario: $destName", 'error');
            continue;
        }

        $result['dates'][] = $prodDate;
        if ($hadBackup) {
            $result['replaced'][] = $prodDate;
        } else {
            $result['created'][] = $prodDate;
        }
    }

    $result['success'] = $result['dates'] !== [];
    return $result;
}

function hasDailyBackupForDate(string $dateYmd, string $sourceBasename): bool {
    if (!is_dir(BACKUP_FOLDER)) {
        return false;
    }
    $pattern = BACKUP_FOLDER . '/BACKUP_' . $dateYmd . '_2359_' . $sourceBasename;
    return is_file($pattern);
}

function finalizeDailyBackups(?string $sourceFile = null): array {
    $tz   = new DateTimeZone('America/Costa_Rica');
    $now  = new DateTimeImmutable('now', $tz);
    $done = [];

    $source = $sourceFile ?: (function_exists('findLatestCSV') ? findLatestCSV() : null);
    if (!$source || !is_file($source)) {
        return $done;
    }

    $basename = basename($source);
    $targets  = [$now->format('Y-m-d')];
    if ($now->format('Hi') < '1200') {
        $targets[] = $now->modify('-1 day')->format('Y-m-d');
    }

    foreach (array_unique($targets) as $dateYmd) {
        $compact = str_replace('-', '', $dateYmd);
        if (hasDailyBackupForDate($compact, $basename)) {
            continue;
        }
        if (backupCSV($source, $compact . '_2359')) {
            $done[] = $compact . '_2359';
            logMessage("Respaldo diario histórico: $dateYmd");
        }
    }

    return $done;
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

// =============================================================================
// HISTÓRICO POR RANGO DE FECHAS
// =============================================================================

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
        'total'                 => $s['total'],
        'jobs_unicos'           => $s['jobs_unicos'],
        'jobs_unicos_afectados' => $s['jobs_unicos_afectados'],
        'jobs_con_brea'         => $s['jobs_con_brea'],
        'total_lentes_brea'     => $s['total_lentes_brea'],
        'brea_tasa'             => $s['brea_tasa'],
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
            if ($backups === []) {
                continue;
            }

            foreach ($backups as $chosen) {
                $path = BACKUP_FOLDER . '/' . $chosen['filename'];
                if (!is_file($path)) {
                    continue;
                }

                $data = processCSV($path);
                if (!$data || empty($data['records'])) {
                    unset($data);
                    continue;
                }

                $fileRecords = filterRecordsByDateRange($data['records'], $dateKey, $dateKey);
                unset($data); // liberar RAM inmediatamente tras extraer los registros del día

                if ($fileRecords === []) {
                    continue;
                }

                $dayRecords = array_merge($dayRecords, $fileRecords);
                $filesLoaded[] = [
                    'date'     => $dateKey,
                    'source'   => 'backup',
                    'filename' => $chosen['filename'],
                    'records'  => count($fileRecords),
                    'is_daily' => !empty($chosen['is_daily']),
                ];
            }

            if ($dayRecords === []) {
                continue;
            }
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

    $records = sortRecordsNewestFirst($merged);

    return [
        'records'      => $records,
        'stats'        => calculateStatsCorrected($records),
        'breakages'    => getBreakagesConsolidated($records),
        'device_stats' => getDeviceStats($records),
        'filename'     => "RANGO_{$dateFrom}_a_{$dateTo}",
        'source'       => 'backup_range',
        'filters'      => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'hour_from' => $hourFrom, 'hour_to' => $hourTo],
        'range_meta'   => [
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'days_in_range'  => $days,
            'days_with_data' => count($statsByDay),
            'files_loaded'   => $filesLoaded,
            'records_count'  => count($records),
        ],
        'stats_by_day' => $statsByDay,
    ];
}

// =============================================================================
// BÚSQUEDA DE JOB EN VIVO + BACKUPS HISTÓRICOS
// =============================================================================

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
    $sources  = [];
    $total    = 0;

    // Datos en vivo
    $liveRecords = sortRecordsNewestFirst(filterRecordsByJob(collectLiveRecordsForSearch(), $jobQuery));
    if (!empty($liveRecords)) {
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

        $matches = sortRecordsNewestFirst($matches);
        $label   = $meta['label'] . ($meta['is_daily'] ? ' · backup diario 23:59' : ' · backup');

        $sources[] = [
            'id'       => 'backup_' . $meta['date'],
            'label'    => $label,
            'date'     => $meta['date'],
            'filename' => $meta['filename'],
            'is_live'  => false,
            'records'  => $matches,
        ];
        $total += count($matches);
    }

    return [
        'job_query'       => $jobQuery,
        'sources'         => $sources,
        'total_records'   => $total,
        'sources_count'   => count($sources),
    ];
}

// =============================================================================
// FUNCIONES DE LIMPIEZA Y LOGGING
// =============================================================================

/**
 * @deprecated Usar cleanupBackupsOnePerDay() de cleanup.php.
 *             Esta versión solo elimina backups intermedios (no-_2359_),
 *             pero no garantiza 1 backup por día si hay múltiples _2359_.
 *             Se mantiene por compatibilidad con código existente.
 */
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

// =============================================================================
// FUNCIONES DE ÍNDICE DE BACKUPS PARA EL MÓDULO HISTÓRICO
// =============================================================================

/**
 * Reconstruye el índice de backups escaneando la carpeta BACKUP_FOLDER
 */
function rebuildBackupIndex(): bool {
    $backupFolder = BACKUP_FOLDER;
    if (!is_dir($backupFolder)) {
        logMessage("rebuildBackupIndex: BACKUP_FOLDER no existe: $backupFolder", 'error');
        return false;
    }
    
    $files = glob($backupFolder . '/BACKUP_*.csv');
    if (empty($files)) {
        logMessage("rebuildBackupIndex: No se encontraron backups en $backupFolder");
        $index = ['files' => [], 'last_rebuild' => time()];
        file_put_contents(BACKUP_INDEX_FILE, json_encode($index));
        return true;
    }
    
    $index = ['files' => [], 'last_rebuild' => time()];
    
    foreach ($files as $file) {
        $filename = basename($file);
        
        // Extraer fecha del nombre del backup (formato BACKUP_YYYYMMDD_...)
        if (preg_match('/BACKUP_(\d{4})(\d{2})(\d{2})_/', $filename, $matches)) {
            $date = $matches[1] . '-' . $matches[2] . '-' . $matches[3];
            $index['files'][$filename] = [
                'date' => $date,
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
                'is_daily' => str_contains($filename, '_2359_')
            ];
        } else {
            // Fallback: usar fecha de modificación
            $date = date('Y-m-d', filemtime($file));
            $index['files'][$filename] = [
                'date' => $date,
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
                'is_daily' => str_contains($filename, '_2359_')
            ];
        }
    }
    
    // Ordenar por fecha (más reciente primero)
    uasort($index['files'], function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
    
    $result = file_put_contents(BACKUP_INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT));
    if ($result === false) {
        logMessage("rebuildBackupIndex: No se pudo guardar el índice en " . BACKUP_INDEX_FILE, 'error');
        return false;
    }
    
    logMessage("rebuildBackupIndex: Indexados " . count($index['files']) . " backups");
    return true;
}

/**
 * Carga el índice de backups desde el archivo JSON
 */
function loadBackupIndex(): array {
    if (!file_exists(BACKUP_INDEX_FILE)) {
        // Si no existe el índice, reconstruirlo
        rebuildBackupIndex();
    }
    
    if (file_exists(BACKUP_INDEX_FILE)) {
        $content = file_get_contents(BACKUP_INDEX_FILE);
        if ($content !== false) {
            $index = json_decode($content, true);
            if (is_array($index) && isset($index['files'])) {
                return $index;
            }
        }
    }
    
    return ['files' => [], 'last_rebuild' => 0];
}

/**
 * Construye el array de backups agrupados por fecha para la API
 */
function buildBackupsByDateForApi(): array {
    $index = loadBackupIndex();
    $byDate = [];
    
    foreach ($index['files'] as $filename => $info) {
        $date = $info['date'];
        if (!isset($byDate[$date])) {
            $byDate[$date] = [
                'date' => $date,
                'label' => date('d/m/Y', strtotime($date)),
                'is_today' => ($date == date('Y-m-d')),
                'has_daily' => false,
                'all' => []
            ];
        }
        
        $backupItem = [
            'filename' => $filename,
            'size' => $info['size'],
            'modified' => $info['modified'],
            'is_daily' => $info['is_daily'] ?? str_contains($filename, '_2359_')
        ];
        
        if ($backupItem['is_daily']) {
            $byDate[$date]['has_daily'] = true;
            $byDate[$date]['daily_backup'] = $backupItem;
        }
        
        $byDate[$date]['all'][] = $backupItem;
        
        // Para compatibilidad con el frontend que espera 'backup'
        if (!isset($byDate[$date]['backup']) || $backupItem['is_daily']) {
            $byDate[$date]['backup'] = $backupItem;
        }
    }
    
    // Ordenar por fecha descendente
    krsort($byDate);
    
    // Convertir a array indexado
    $result = [];
    foreach ($byDate as $date => $data) {
        // Ordenar backups por hora (los diarios al final)
        usort($data['all'], function($a, $b) {
            if ($a['is_daily'] && !$b['is_daily']) return 1;
            if (!$a['is_daily'] && $b['is_daily']) return -1;
            return strcmp($a['modified'], $b['modified']);
        });
        
        $result[] = $data;
    }
    
    return $result;
}

/**
 * Obtiene el payload de un día específico (para el módulo histórico)
 */
function buildHistLivePayload(string $dateFilter, string $hourFrom = '', string $hourTo = ''): ?array {
    $records = collectLiveRecordsForSearch();
    $records = filterRecordsByDateRange($records, $dateFilter, $dateFilter);
    $records = filterRecordsByHourRange($records, $hourFrom !== '' ? (int)$hourFrom : null, $hourTo !== '' ? (int)$hourTo : null);
    
    if (empty($records)) {
        return null;
    }
    
    return [
        'records' => sortRecordsNewestFirst($records),
        'stats' => calculateStatsCorrected($records),
        'breakages' => getBreakagesConsolidated($records),
        'device_stats' => getDeviceStats($records),
        'source' => 'live',
        'filters' => ['date_filter' => $dateFilter, 'hour_from' => $hourFrom, 'hour_to' => $hourTo]
    ];
}

/**
 * Fuerza la sincronización de datos en vivo
 */
function syncLiveData(bool $forceRefresh = false, string $source = 'monitor'): array {
    $latest = findLatestDataSource();
    if (!$latest) {
        return ['success' => false, 'error' => 'No se encontraron archivos CSV para sincronizar'];
    }

    $payload = buildLiveDataPayload($latest);
    if (!$payload || empty($payload['records'])) {
        return ['success' => false, 'error' => 'No se pudieron procesar los datos del archivo'];
    }

    saveCache($payload);

    $backupSync = ensureCSVBackups($latest);

    $records = count($payload['records']);
    saveSyncMeta($source, basename($latest), $records);

    return [
        'success'     => true,
        'records'     => $records,
        'source_file' => basename($latest),
        'data_source' => isBackupFile($latest) ? 'backup' : 'upload',
        'backup_sync' => $backupSync,
    ];
}

function syncMetaFile(): string {
    return dirname(CACHE_FILE) . '/sync_meta.json';
}

function saveSyncMeta(string $source, string $file, int $records): void {
    $metaFile = syncMetaFile();
    $existing = readSyncMeta();
    $today    = date('Y-m-d');

    $sourceLabels = [
        'web_upload'  => 'Subida web',
        'monitor'     => 'Monitor automático',
        'manual_sync' => 'Sincronización manual',
        'auto_sync'   => 'Auto-sync',
        'script'      => 'Script externo (PowerShell)',
    ];

    $count = ($existing['sync_count_today'] ?? 0);
    if (($existing['sync_date'] ?? '') === $today) {
        $count++;
    } else {
        $count = 1;
    }

    $meta = [
        'last_sync'        => date('Y-m-d H:i:s'),
        'last_sync_ts'     => time(),
        'source'           => $source,
        'source_label'     => $sourceLabels[$source] ?? $source,
        'file'             => $file,
        'records'          => $records,
        'sync_date'        => $today,
        'sync_count_today' => $count,
        'server_started'   => $existing['server_started'] ?? date('Y-m-d H:i:s'),
    ];

    @file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function readSyncMeta(): array {
    $metaFile = syncMetaFile();
    if (!file_exists($metaFile)) return [];
    $json = @file_get_contents($metaFile);
    if (!$json) return [];
    return json_decode($json, true) ?: [];
}

function readLastLogLines(int $n = 30): array {
    if (!defined('LOG_FILE') || !file_exists(LOG_FILE)) return [];
    $lines = @file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    return array_slice($lines, -$n);
}

function logMessage(string $message, string $level = 'info'): void {
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] [$level] $message" . PHP_EOL;
    @file_put_contents(LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
}
