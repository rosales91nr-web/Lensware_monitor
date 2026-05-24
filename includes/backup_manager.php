<?php
// includes/backup_manager.php - Respaldos e índice para el módulo histórico
// VERSIÓN RAILWAY - Todas las rutas usan /tmp para garantizar permisos de escritura

// =============================================================================
// CONSTANTES - Usar /tmp en Railway, fallback local
// =============================================================================

// Detectar si estamos en Railway
$isRailway = getenv('RAILWAY_ENVIRONMENT') === 'production' || 
             getenv('RAILWAY_SERVICE_ID') !== false ||
             getenv('APP_ENV') === 'railway';

if ($isRailway) {
    // Railway: usar ruta absoluta /tmp/lensware para evitar inconsistencias de permisos
    $tmpBase = '/tmp/lensware';
    if (!defined('BACKUP_STATE_FILE')) {
        define('BACKUP_STATE_FILE', $tmpBase . '/backups/state.json');
    }
    if (!defined('BACKUP_INDEX_FILE')) {
        define('BACKUP_INDEX_FILE', $tmpBase . '/backups/backup_index.json');
    }
    if (!defined('BACKUP_FOLDER')) {
        define('BACKUP_FOLDER', $tmpBase . '/backups');
    }
} else {
    // Local: usar cache del proyecto
    if (!defined('BACKUP_STATE_FILE')) {
        define('BACKUP_STATE_FILE', __DIR__ . '/../cache/backup_state.json');
    }
    if (!defined('BACKUP_INDEX_FILE')) {
        define('BACKUP_INDEX_FILE', __DIR__ . '/../cache/backup_index.json');
    }
}

if (!defined('BACKUP_INTERVAL_SEC')) {
    define('BACKUP_INTERVAL_SEC', (int)(getenv('BACKUP_INTERVAL_SEC') ?: 300));
}

// Crear carpeta de backups si no existe
$backupDir = dirname(BACKUP_STATE_FILE);
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0777, true);
}

// =============================================================================
// Estado e índice
// =============================================================================

function loadBackupState(): array
{
    if (!defined('BACKUP_STATE_FILE') || !is_file(BACKUP_STATE_FILE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(BACKUP_STATE_FILE), true);
    return is_array($data) ? $data : [];
}

function saveBackupState(array $state): void
{
    if (!defined('BACKUP_STATE_FILE')) return;
    $dir = dirname(BACKUP_STATE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents(BACKUP_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function loadBackupIndex(): array
{
    if (!defined('BACKUP_INDEX_FILE') || !is_file(BACKUP_INDEX_FILE)) {
        return ['files' => [], 'production_dates' => []];
    }
    $data = json_decode((string) file_get_contents(BACKUP_INDEX_FILE), true);
    if (!is_array($data)) {
        return ['files' => [], 'production_dates' => []];
    }
    $data['files'] = $data['files'] ?? [];
    $data['production_dates'] = $data['production_dates'] ?? [];
    return $data;
}

function saveBackupIndex(array $index): void
{
    if (!defined('BACKUP_INDEX_FILE')) return;
    $dir = dirname(BACKUP_INDEX_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents(BACKUP_INDEX_FILE, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function parseBackupStampFromFilename(string $filename): ?array
{
    if (!preg_match('/^BACKUP_(\d{4})(\d{2})(\d{2})_(\d{4,6})_/i', $filename, $m)) {
        return null;
    }
    return [
        'date'      => "{$m[1]}-{$m[2]}-{$m[3]}",
        'time'      => $m[4],
        'is_daily'  => ($m[4] === '2359'),
        'file_date' => "{$m[1]}-{$m[2]}-{$m[3]}",
    ];
}

function indexBackupFile(string $backupPath): void
{
    if (!is_file($backupPath)) {
        return;
    }

    $filename = basename($backupPath);
    $stamp    = parseBackupStampFromFilename($filename);
    $meta     = [
        'filename'    => $filename,
        'size'        => filesize($backupPath),
        'modified'    => date('Y-m-d H:i:s', filemtime($backupPath)),
        'is_daily'    => $stamp ? $stamp['is_daily'] : str_contains($filename, '_2359_'),
        'backup_date' => $stamp['file_date'] ?? substr(date('Y-m-d', filemtime($backupPath)), 0, 10),
        'production_dates' => [],
        'record_count'     => 0,
    ];

    if (function_exists('processCSV')) {
        $data = processCSV($backupPath);
        if ($data && !empty($data['records'])) {
            $dates = [];
            foreach ($data['records'] as $r) {
                $d = normalizeRecordDate($r['date_raw'] ?? '');
                if ($d) {
                    $dates[$d] = true;
                }
            }
            $meta['production_dates'] = array_keys($dates);
            sort($meta['production_dates']);
            $meta['record_count'] = count($data['records']);
        }
    }

    $index = loadBackupIndex();
    $index['files'][$filename] = $meta;
    $allDates = [];
    foreach ($index['files'] as $f) {
        foreach ($f['production_dates'] ?? [] as $d) {
            $allDates[$d] = true;
        }
    }
    $index['production_dates'] = array_keys($allDates);
    rsort($index['production_dates']);
    saveBackupIndex($index);
}

function rebuildBackupIndex(): int
{
    if (!defined('BACKUP_FOLDER')) return 0;
    $index = ['files' => [], 'production_dates' => []];
    saveBackupIndex($index);
    $count = 0;
    foreach (glob(BACKUP_FOLDER . DIRECTORY_SEPARATOR . 'BACKUP_*.csv') ?: [] as $f) {
        indexBackupFile($f);
        $count++;
    }
    return $count;
}

// =============================================================================
// Respaldos por fecha de producción (un archivo por día)
// =============================================================================

/** Agrupa líneas del CSV origen por fecha de producción (columna Date). */
function splitSourceCsvByProductionDate(string $filepath): ?array
{
    if (!is_file($filepath)) {
        return null;
    }

    $raw = file_get_contents($filepath);
    if ($raw === false) {
        return null;
    }

    $raw   = ltrim($raw, "\xEF\xBB\xBF");
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
        if (!isset($byDate[$prodDate])) {
            $byDate[$prodDate] = [];
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

function productionDateHasBackup(string $productionDate): bool
{
    foreach (getBackupsCoveringProductionDate($productionDate) as $b) {
        if (!empty($b['is_daily']) || str_contains($b['filename'], '_2359_')) {
            return true;
        }
    }
    return count(getBackupsCoveringProductionDate($productionDate)) > 0;
}

/** Elimina todos los respaldos asociados a una fecha de producción. */
function removeBackupsForProductionDate(string $productionDate): int
{
    if (!defined('BACKUP_FOLDER')) return 0;
    $deleted = [];
    $compact = str_replace('-', '', $productionDate);

    foreach (glob(BACKUP_FOLDER . DIRECTORY_SEPARATOR . 'BACKUP_' . $compact . '_*.csv') ?: [] as $f) {
        $deleted[basename($f)] = true;
        @unlink($f);
    }

    $index = loadBackupIndex();
    foreach ($index['files'] as $filename => $meta) {
        $dates = $meta['production_dates'] ?? [];
        if (in_array($productionDate, $dates, true)) {
            $path = BACKUP_FOLDER . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                @unlink($path);
            }
            $deleted[$filename] = true;
            unset($index['files'][$filename]);
        }
    }

    $allDates = [];
    foreach ($index['files'] as $f) {
        foreach ($f['production_dates'] ?? [] as $d) {
            $allDates[$d] = true;
        }
    }
    $index['production_dates'] = array_keys($allDates);
    rsort($index['production_dates']);
    saveBackupIndex($index);

    return count($deleted);
}

function writeDailyBackupCsv(string $destPath, string $headerLine, array $dataLines): bool
{
    $content = "\xEF\xBB\xBF" . $headerLine . "\r\n" . implode("\r\n", $dataLines) . "\r\n";
    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return file_put_contents($destPath, $content, LOCK_EX) !== false;
}

/**
 * Detecta fechas en el CSV entrante y crea/reemplaza un respaldo diario por cada día.
 */
function syncProductionDateBackupsFromCsv(string $sourcePath): array
{
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

    if (!defined('BACKUP_FOLDER')) {
        $result['error'] = 'BACKUP_FOLDER no definido';
        return $result;
    }

    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_writable($dir)) {
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

        $hadBackup = productionDateHasBackup($prodDate);
        removeBackupsForProductionDate($prodDate);

        $compact  = str_replace('-', '', $prodDate);
        $destName = 'BACKUP_' . $compact . '_2359_' . $basename;
        $destPath = $dir . DIRECTORY_SEPARATOR . $destName;

        if (!writeDailyBackupCsv($destPath, $split['header_line'], $lines)) {
            if (function_exists('logMessage')) {
                logMessage("No se pudo escribir respaldo diario: $destName", 'error');
            }
            continue;
        }

        indexBackupFile($destPath);
        $result['dates'][] = $prodDate;

        if ($hadBackup) {
            $result['replaced'][] = $prodDate;
            if (function_exists('logMessage')) {
                logMessage("Respaldo reemplazado para producción $prodDate (" . count($lines) . " filas)");
            }
        } else {
            $result['created'][] = $prodDate;
            if (function_exists('logMessage')) {
                logMessage("Respaldo nuevo para producción $prodDate (" . count($lines) . " filas)");
            }
        }
    }

    $result['success'] = $result['dates'] !== [];
    return $result;
}

// =============================================================================
// Crear respaldos (copia completa o uso legacy)
// =============================================================================

function backupCSV(string $filepath, ?string $timestamp = null): bool
{
    if (!file_exists($filepath)) {
        return false;
    }
    if (!defined('BACKUP_FOLDER')) return false;
    
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true)) {
            if (function_exists('logMessage')) {
                logMessage("backupCSV: no se pudo crear $dir", 'error');
            }
            return false;
        }
    }

    $stamp = $timestamp ?? date('Ymd_His');
    $dest  = $dir . DIRECTORY_SEPARATOR . 'BACKUP_' . $stamp . '_' . basename($filepath);

    if (file_exists($dest)) {
        return true;
    }

    if (!@copy($filepath, $dest)) {
        if (function_exists('logMessage')) {
            logMessage("backupCSV: falló copy() a $dest", 'error');
        }
        return false;
    }

    if (function_exists('logMessage')) {
        logMessage('Respaldo: ' . basename($dest));
    }
    indexBackupFile($dest);
    return true;
}

function hasDailyBackupForDate(string $dateYmd, string $sourceBasename): bool
{
    if (!defined('BACKUP_FOLDER')) return false;
    $pattern = BACKUP_FOLDER . DIRECTORY_SEPARATOR . 'BACKUP_' . $dateYmd . '_2359_' . $sourceBasename;
    return is_file($pattern);
}

/** Cierra el día: crea BACKUP_YYYYMMDD_2359 si faltó. */
function finalizeDailyBackups(?string $sourceFile = null): array
{
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
            if (function_exists('logMessage')) {
                logMessage("Respaldo diario histórico: $dateYmd");
            }
        }
    }

    return $done;
}

/**
 * Respaldos inteligentes al sincronizar
 */
function ensureCSVBackups(string $filepath): array
{
    if (!file_exists($filepath)) {
        return ['success' => false, 'error' => 'Archivo no existe'];
    }

    if (!defined('BACKUP_FOLDER')) {
        return ['success' => false, 'error' => 'BACKUP_FOLDER no definido'];
    }

    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (!is_writable($dir)) {
        if (function_exists('logMessage')) {
            logMessage('BACKUP_FOLDER sin escritura: ' . $dir, 'error');
        }
        return ['success' => false, 'error' => 'Sin permisos'];
    }

    $sync = syncProductionDateBackupsFromCsv($filepath);

    $state = loadBackupState();
    $state['last_path']  = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filepath));
    $state['last_mtime'] = @filemtime($filepath) ?: 0;
    $state['last_sync']  = time();
    saveBackupState($state);

    return $sync;
}

function listBackups(): array
{
    if (!defined('BACKUP_FOLDER')) return [];
    
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) {
        return [];
    }

    $index = loadBackupIndex();
    $list  = [];

    foreach (glob($dir . DIRECTORY_SEPARATOR . 'BACKUP_*.csv') ?: [] as $f) {
        $name = basename($f);
        $idx  = $index['files'][$name] ?? [];
        $stamp = parseBackupStampFromFilename($name);

        $list[] = [
            'filename'         => $name,
            'name'             => $name,
            'size'             => filesize($f),
            'modified'         => date('Y-m-d H:i:s', filemtime($f)),
            'is_daily'         => $idx['is_daily'] ?? ($stamp['is_daily'] ?? str_contains($name, '_2359_')),
            'backup_date'      => $idx['backup_date'] ?? ($stamp['file_date'] ?? substr(date('Y-m-d', filemtime($f)), 0, 10)),
            'production_dates' => $idx['production_dates'] ?? [],
            'record_count'     => $idx['record_count'] ?? 0,
        ];
    }

    usort($list, fn($a, $b) => strcmp($b['modified'], $a['modified']));
    return $list;
}

// =============================================================================
// Histórico por fecha de producción
// =============================================================================

function getAvailableProductionDates(): array
{
    $index = loadBackupIndex();
    $dates = $index['production_dates'] ?? [];

    if ($dates === [] && defined('BACKUP_FOLDER') && is_dir(BACKUP_FOLDER)) {
        rebuildBackupIndex();
        $dates = loadBackupIndex()['production_dates'] ?? [];
    }

    $today = function_exists('appTodayDate') ? appTodayDate() : date('Y-m-d');
    if (!in_array($today, $dates, true)) {
        $live = function_exists('collectLiveRecordsForSearch') ? collectLiveRecordsForSearch() : [];
        if ($live !== []) {
            $dates[] = $today;
        }
    }

    $dates = array_unique($dates);
    rsort($dates);
    return array_values($dates);
}

function getBackupsCoveringProductionDate(string $productionDate): array
{
    $all = listBackups();
    $matched = [];

    foreach ($all as $b) {
        $prodDates = $b['production_dates'] ?? [];
        if ($prodDates !== [] && in_array($productionDate, $prodDates, true)) {
            $matched[] = $b;
            continue;
        }
        if ($prodDates === [] && ($b['backup_date'] ?? '') === $productionDate) {
            $matched[] = $b;
        }
    }

    if ($matched === []) {
        $compact = str_replace('-', '', $productionDate);
        foreach ($all as $b) {
            if (str_contains($b['filename'], 'BACKUP_' . $compact)) {
                $matched[] = $b;
            }
        }
    }

    usort($matched, function ($a, $b) {
        if ($a['is_daily'] !== $b['is_daily']) {
            return $b['is_daily'] <=> $a['is_daily'];
        }
        return strcmp($b['modified'], $a['modified']);
    });

    return $matched;
}

function pickOfficialBackupForProductionDate(string $productionDate): ?array
{
    $backups = getBackupsCoveringProductionDate($productionDate);
    if ($backups === []) {
        return null;
    }

    $compact = str_replace('-', '', $productionDate);
    foreach ($backups as $b) {
        if (!empty($b['is_daily']) || str_contains($b['filename'], 'BACKUP_' . $compact . '_2359_')) {
            return $b;
        }
    }

    return $backups[0];
}

/** Lista para api.php?action=backups_by_date (por fecha de producción). */
function buildBackupsByDateForApi(): array
{
    $today  = function_exists('appTodayDate') ? appTodayDate() : date('Y-m-d');
    $dates  = getAvailableProductionDates();
    $result = [];

    if ($dates === []) {
        $byFile = groupBackupsByDateFromList(listBackups());
        $dates  = array_keys($byFile);
    }

    foreach ($dates as $date) {
        $isToday = ($date === $today);
        $all     = getBackupsCoveringProductionDate($date);
        $chosen  = pickOfficialBackupForProductionDate($date);

        $result[] = [
            'date'     => $date,
            'label'    => $isToday ? 'Hoy' : date('d/m/Y', strtotime($date)),
            'is_today' => $isToday,
            'backup'   => $chosen,
            'all'      => $all,
            'has_daily'=> (bool) array_filter($all, fn($b) => !empty($b['is_daily'])),
        ];
    }

    return $result;
}

function buildHistLivePayload(string $productionDate, string $hourFrom = '', string $hourTo = ''): ?array
{
    $live = function_exists('collectLiveRecordsForSearch') ? collectLiveRecordsForSearch() : [];
    if ($live === []) {
        $source = function_exists('findLatestDataSource') ? findLatestDataSource() : null;
        if ($source && function_exists('processCSV')) {
            $data = processCSV($source);
            $live = $data['records'] ?? [];
        }
    }

    if (function_exists('filterRecordsByDateRange')) {
        $records = filterRecordsByDateRange($live, $productionDate, $productionDate);
    } else {
        $records = $live;
    }
    
    if (function_exists('filterRecordsByHourRange')) {
        $records = filterRecordsByHourRange(
            $records,
            $hourFrom !== '' ? (int) $hourFrom : null,
            $hourTo !== '' ? (int) $hourTo : null
        );
    }

    if ($records === []) {
        return null;
    }

    return [
        'records'      => $records,
        'stats'        => function_exists('calculateStatsCorrected') ? calculateStatsCorrected($records) : [],
        'breakages'    => function_exists('getBreakagesConsolidated') ? getBreakagesConsolidated($records) : [],
        'device_stats' => function_exists('getDeviceStats') ? getDeviceStats($records) : [],
        'filename'     => 'REPORTS en vivo',
        'source'       => 'live',
        'filters'      => [
            'date_filter' => $productionDate,
            'hour_from'   => $hourFrom,
            'hour_to'     => $hourTo,
        ],
    ];
}

// =============================================================================
// Compatibilidad con código existente
// =============================================================================

function groupBackupsByDateFromList(array $all): array
{
    $byDate = [];
    foreach ($all as $b) {
        $dates = $b['production_dates'] ?? [];
        if ($dates === []) {
            $stamp = parseBackupStampFromFilename($b['filename']);
            $dates = [$stamp['file_date'] ?? substr($b['modified'], 0, 10)];
        }
        foreach ($dates as $dateKey) {
            $byDate[$dateKey][] = $b;
        }
    }
    krsort($byDate);
    return $byDate;
}

function pickOfficialBackupMeta(array $backups, bool $isToday): ?array
{
    if ($backups === []) {
        return null;
    }
    foreach ($backups as $b) {
        if (!empty($b['is_daily']) || str_contains($b['filename'], '_2359_')) {
            return $b;
        }
    }
    return $backups[0];
}

function cleanupOldBackups(int $keepIncrementalDays = 7): array
{
    if (!defined('BACKUP_FOLDER')) return ['deleted' => 0, 'kept' => 0, 'files_deleted' => []];
    
    $dir = BACKUP_FOLDER;
    if (!is_dir($dir)) {
        return ['deleted' => 0, 'kept' => 0, 'files_deleted' => []];
    }

    $tz     = new DateTimeZone('America/Costa_Rica');
    $cutoff = (new DateTimeImmutable('now', $tz))->modify("-{$keepIncrementalDays} days");
    $deleted = [];
    $kept    = 0;

    foreach (glob($dir . DIRECTORY_SEPARATOR . 'BACKUP_*.csv') ?: [] as $f) {
        $name = basename($f);
        if (str_contains($name, '_2359_')) {
            $kept++;
            continue;
        }

        $mtime = (new DateTimeImmutable('@' . filemtime($f)))->setTimezone($tz);
        if ($mtime >= $cutoff) {
            $kept++;
            continue;
        }

        if (@unlink($f)) {
            $deleted[] = $name;
            $index = loadBackupIndex();
            unset($index['files'][$name]);
            saveBackupIndex($index);
        }
    }

    if (count($deleted) > 0) {
        rebuildBackupIndex();
    }

    return ['deleted' => count($deleted), 'kept' => $kept, 'files_deleted' => $deleted];
}

// Legacy stubs
function getLastBackupTimestamp(): int
{
    $s = loadBackupState();
    return (int) ($s['last_mtime'] ?? 0);
}

function saveLastBackupTimestamp(int $ts): void
{
    $s = loadBackupState();
    $s['last_mtime'] = $ts;
    saveBackupState($s);
}

function getLastCSVBackup(string $filepath): ?string
{
    if (!defined('BACKUP_FOLDER')) return null;
    $files = glob(BACKUP_FOLDER . DIRECTORY_SEPARATOR . 'BACKUP_*_' . basename($filepath)) ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files[0] ?? null;
}

function hasDailyCSVBackup(string $filepath, DateTimeInterface $date): bool
{
    return hasDailyBackupForDate($date->format('Ymd'), basename($filepath));
}
