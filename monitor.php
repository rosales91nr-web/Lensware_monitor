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

$now = new DateTimeImmutable('now', new DateTimeZone('America/Costa_Rica'));
$lastBackup = getLastCSVBackup($latestCSV);
if (!$lastBackup || filemtime($latestCSV) > filemtime($lastBackup)) {
    echo "Creando respaldo por actualización...\n";
    backupCSV($latestCSV);
} else {
    echo "No hay cambios nuevos desde el último respaldo.\n";
}

// Respaldo diario antes de cambiar de día (23:55 - 23:59 hora Costa Rica)
if ($now->format('Hi') >= '2355' && $now->format('Hi') <= '2359' && !hasDailyCSVBackup($latestCSV, $now)) {
    echo "Creando respaldo diario 23:59...\n";
    backupCSV($latestCSV, $now->format('Ymd_2359'));
}

// Limpiar caché para forzar recarga
if (file_exists(CACHE_FILE)) {
    unlink(CACHE_FILE);
    echo "Caché limpiado.\n";
}

echo "Monitor ejecutado correctamente.\n";
