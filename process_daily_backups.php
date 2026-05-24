<?php
// process_daily_backups.php
// Genera respaldos diarios (BACKUP_YYYYMMDD_2359_*.csv) a partir de un CSV de origen.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

if (PHP_SAPI !== 'cli') {
    echo "Este script debe ejecutarse desde la línea de comandos.\n";
    exit(1);
}

if ($argc < 2) {
    echo "Uso: php process_daily_backups.php /ruta/al/archivo.csv\n";
    exit(1);
}

$sourcePath = $argv[1];
if (!is_file($sourcePath)) {
    echo "Archivo no encontrado: {$sourcePath}\n";
    exit(1);
}

$result = syncProductionDateBackupsFromCsv($sourcePath);
if (!$result['success']) {
    echo "Error: " . ($result['error'] ?? 'No se pudo generar los respaldos diarios') . "\n";
    if (!empty($result['dates'])) {
        echo "Fechas procesadas: " . implode(', ', $result['dates']) . "\n";
    }
    exit(1);
}

$created = $result['created'] ?: [];
$replaced = $result['replaced'] ?: [];
$dates = $result['dates'] ?: [];

echo "Respaldo diario generado correctamente.\n";
echo "Fechas procesadas: " . implode(', ', $dates) . "\n";
if ($created) {
    echo "Creados: " . implode(', ', $created) . "\n";
}
if ($replaced) {
    echo "Reemplazados: " . implode(', ', $replaced) . "\n";
}
exit(0);
