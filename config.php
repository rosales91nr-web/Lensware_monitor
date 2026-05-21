<?php
// config.php — Lensware Pro (Multi-entorno: Windows local / Railway)

date_default_timezone_set('America/Costa_Rica');

// Detectar entorno Railway
$isRailway = getenv('RAILWAY_ENVIRONMENT') === 'production' || 
             getenv('RAILWAY_SERVICE_ID') !== false ||
             getenv('APP_ENV') === 'railway';

// Cargar .env del proyecto (opcional)
if (file_exists(__DIR__ . '/.env')) {
    loadLocalEnv(__DIR__ . '/.env');
}

// ─────────────────────────────────────────────────────
// CONFIGURACIÓN PARA RAILWAY (Linux)
// ─────────────────────────────────────────────────────
if ($isRailway) {
    // Railway: usar rutas configurables para soportar volúmenes persistentes
    $tmpBase = getenv('TMP_BASE') ?: '/tmp/lensware';
    $backupPath = getenv('BACKUP_FOLDER') ?: '/var/www/html/backups';
    $cachePath  = getenv('CACHE_FILE') ?: $tmpBase . '/cache/cache.json';
    $logPath    = getenv('LOG_FILE') ?: $tmpBase . '/logs/app.log';

    define('APP_BASE', $tmpBase);
    define('WATCH_FOLDER', getenv('WATCH_FOLDER') ?: $tmpBase . '/staging');
    define('STAGING_FOLDER', getenv('STAGING_FOLDER') ?: $tmpBase . '/staging');
    define('BACKUP_FOLDER', $backupPath);
    define('CACHE_FILE', $cachePath);
    define('BACKUP_INDEX_FILE', BACKUP_FOLDER . '/backup_index.json');
    define('BACKUP_STATE_FILE', BACKUP_FOLDER . '/state.json');
    define('LOG_FILE', $logPath);

    // Crear todas las carpetas necesarias con permisos de escritura
    $dirs = [
        $tmpBase,
        WATCH_FOLDER,
        STAGING_FOLDER,
        BACKUP_FOLDER,
        dirname(CACHE_FILE),
        dirname(LOG_FILE),
    ];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        @chmod($dir, 0777);
    }
}
// ─────────────────────────────────────────────────────
// CONFIGURACIÓN PARA WINDOWS LOCAL (XAMPP)
// ─────────────────────────────────────────────────────
else {
    $reportsFolder = getenv('REPORTS_FOLDER') ?: '\\\\172.16.8.32\\Lensware\\LensSOAPServer_INT\\www\\REPORTS';
    define('REPORTS_FOLDER', $reportsFolder);
    define('WATCH_FOLDER', getenv('WATCH_FOLDER') ?: REPORTS_FOLDER);
    define('STAGING_FOLDER', getenv('STAGING_FOLDER') ?: __DIR__ . '/uploads');
    define('BACKUP_FOLDER', getenv('BACKUP_FOLDER') ?: __DIR__ . '/backups');
    define('CACHE_FILE', __DIR__ . '/cache/data.json');
}

// ─────────────────────────────────────────────────────
// CONFIGURACIÓN COMÚN (ambos entornos)
// ─────────────────────────────────────────────────────
define('CSV_PREFIXES', ['UNI_PROD_ALL_ACT_', 'UNI_PROD_SIMPLE_ACT_']);
define('CACHE_TTL', (int)(getenv('CACHE_TTL') ?: 30));
define('BACKUP_RANGE_MAX_DAYS', (int)(getenv('BACKUP_RANGE_MAX_DAYS') ?: 365));
define('UPLOAD_SECRET', getenv('UPLOAD_SECRET') ?: '');
define('APP_ENV', $isRailway ? 'railway' : (getenv('APP_ENV') ?: 'local'));

// ─────────────────────────────────────────────────────
// ETIQUETAS Y COLORES (sin cambios)
// ─────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────
// CREAR CARPETAS EN LOCAL (solo si no es Railway)
// ─────────────────────────────────────────────────────
if (!$isRailway) {
    foreach ([BACKUP_FOLDER, STAGING_FOLDER, __DIR__ . '/cache', __DIR__ . '/logs'] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
}

// ─────────────────────────────────────────────────────
// FUNCIONES AUXILIARES
// ─────────────────────────────────────────────────────
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
