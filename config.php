<?php
// config.php — Lensware Pro (XAMPP local / Windows)

date_default_timezone_set('America/Costa_Rica');

// Cargar .env del proyecto (opcional)
loadLocalEnv(__DIR__ . '/.env');

// Carpeta donde Lensware escribe los CSV (solo lectura desde PHP)
define('REPORTS_FOLDER', getenv('REPORTS_FOLDER') ?: '\\\\172.16.8.32\\Lensware\\LensSOAPServer_INT\\www\\REPORTS');

// Carpeta vigilada = REPORTS por defecto
define('WATCH_FOLDER', normalizeStoragePath(getenv('WATCH_FOLDER') ?: REPORTS_FOLDER));

// Importaciones manuales desde el navegador (no escribir en el share de red)
define('STAGING_FOLDER', normalizeStoragePath(getenv('STAGING_FOLDER') ?: __DIR__ . '/uploads'));

define('BACKUP_FOLDER', normalizeStoragePath(getenv('BACKUP_FOLDER') ?: __DIR__ . '/backups'));
define('CSV_PREFIXES',  ['UNI_PROD_ALL_ACT_', 'UNI_PROD_SIMPLE_ACT_']);

define('CACHE_FILE', __DIR__ . '/cache/data.json');
define('CACHE_TTL',  (int)(getenv('CACHE_TTL') ?: 30));

define('BACKUP_RANGE_MAX_DAYS', (int)(getenv('BACKUP_RANGE_MAX_DAYS') ?: 93));

// Solo necesario si expones upload_csv a la red; en local puede quedar vacío
define('UPLOAD_SECRET', getenv('UPLOAD_SECRET') ?: '');

define('APP_ENV', 'local');

$STATUS_LABELS = [
    'SBLK' => 'Bloqueo',
    'PREP' => 'Calculado',
    'SGEN' => 'Generado',
    'PRNT' => 'Impreso',
    'EDGE' => 'Bisel/Edging',
    'TRAC' => 'Trazado',
    'SPOL' => 'Pulido',
    'SENG' => 'Laser/Grabado',
    'PKRX' => 'Validación RX',
    'WHRX' => 'Almacén Bases',
    'WHST' => 'Almacén Term.',
    'BREA' => 'QUIEBRA',
];

$STATUS_COLORS = [
    'BREA' => '#EF4444',
    'SGEN' => '#2563EB',
    'SPOL' => '#10B981',
    'EDGE' => '#F59E0B',
    'SENG' => '#8B5CF6',
    'SBLK' => '#06B6D4',
    'TRAC' => '#F97316',
    'PREP' => '#3B82F6',
    'PRNT' => '#14B8A6',
    'PKRX' => '#EC4899',
    'WHRX' => '#64748B',
    'WHST' => '#94A3B8',
];

foreach ([BACKUP_FOLDER, STAGING_FOLDER, __DIR__ . '/cache', __DIR__ . '/logs'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function loadLocalEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

function normalizeStoragePath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return $path;
    }
    if (preg_match('#^\\\\#', $path)) {
        return rtrim(str_replace('/', '\\', $path), '\\');
    }
    return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
}
