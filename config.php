<?php
// config.php - Lensware Pro (Railway-ready)

// ---------------------------------------------------------------------------
// Rutas locales (Railway no puede acceder a shares UNC de Windows).
// Sube CSVs a la carpeta /uploads/ vía el endpoint api.php?action=upload_csv
// o montando un volumen en Railway con la ruta /var/www/html/uploads
// ---------------------------------------------------------------------------
date_default_timezone_set('America/Costa_Rica');
define('WATCH_FOLDER',  getenv('WATCH_FOLDER') ?: __DIR__ . '/uploads');
define('BACKUP_FOLDER', getenv('BACKUP_FOLDER') ?: __DIR__ . '/backups');
define('CSV_PREFIXES',  ['UNI_PROD_ALL_ACT_', 'UNI_PROD_SIMPLE_ACT_']);

// Caché JSON
define('CACHE_FILE', __DIR__ . '/cache/data.json');
define('CACHE_TTL',  (int)(getenv('CACHE_TTL') ?: 60)); // segundos

// Clave de API para el endpoint de subida de CSV (define en Railway → Variables)
// Si no defines UPLOAD_SECRET en Railway Variables, el upload no requiere auth (uso interno).
define('UPLOAD_SECRET', getenv('UPLOAD_SECRET') ?: 'changeme');

// Mapeo de estados
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
    'BREA' => 'QUIEBRA'
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
    'WHST' => '#94A3B8'
];

// Logger
function logMessage(string $msg, string $level = 'info'): void {
    $logFile = __DIR__ . '/logs/monitor.log';
    if (!is_dir(dirname($logFile))) {
        mkdir(dirname($logFile), 0777, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] [$level] $msg" . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// Crear carpetas requeridas si no existen
foreach ([BACKUP_FOLDER, __DIR__ . '/cache', __DIR__ . '/uploads', __DIR__ . '/logs'] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}
