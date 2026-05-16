<?php
// monitor.php - Script de monitoreo (Railway: usa uploads/ local)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

echo "Lensware Pro Monitor - " . date('Y-m-d H:i:s') . "\n";
echo "==========================================\n";

$latestCSV = findLatestCSV();

if (!$latestCSV) {
    echo "No se encontraron archivos CSV en: " . WATCH_FOLDER . "\n";
    echo "Sube un CSV via POST /api.php?action=upload_csv\n";
    exit(1);
}

echo "Archivo encontrado: " . basename($latestCSV) . "\n";
echo "Fecha modificación: " . date('Y-m-d H:i:s', filemtime($latestCSV)) . "\n";

// Crear respaldo si no existe uno del día
$todayBackups = glob(BACKUP_FOLDER . '/BACKUP_' . date('Ymd') . '_*_' . basename($latestCSV));
if (empty($todayBackups)) {
    echo "Creando respaldo...\n";
    backupCSV($latestCSV);
} else {
    echo "Respaldo del día ya existe.\n";
}

// Limpiar caché para forzar recarga
if (file_exists(CACHE_FILE)) {
    unlink(CACHE_FILE);
    echo "Caché limpiado.\n";
}

echo "Monitor ejecutado correctamente.\n";
