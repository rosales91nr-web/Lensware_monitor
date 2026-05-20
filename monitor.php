<?php
// monitor.php — Sincroniza CSV desde REPORTS y actualiza caché (ejecutar cada 1-5 min)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

echo "Lensware Pro Monitor - " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 50) . "\n";
echo "REPORTS: " . WATCH_FOLDER . "\n";
echo "Accesible: " . (isWatchFolderAccessible() ? 'SI' : 'NO') . "\n\n";

if (empty(loadBackupIndex()['files'])) {
    $indexed = rebuildBackupIndex();
    echo "Indice de backups: $indexed archivo(s) indexados.\n";
}

$daily = finalizeDailyBackups();
if ($daily !== []) {
    echo "Respaldos diarios: " . implode(', ', $daily) . "\n";
}

$result = syncLiveData(false);

if (!empty($result['backup_sync']['dates'])) {
    echo "Respaldos por fecha: " . implode(', ', $result['backup_sync']['dates']) . "\n";
    if (!empty($result['backup_sync']['created'])) {
        echo "  Nuevos: " . implode(', ', $result['backup_sync']['created']) . "\n";
    }
    if (!empty($result['backup_sync']['replaced'])) {
        echo "  Reemplazados: " . implode(', ', $result['backup_sync']['replaced']) . "\n";
    }
}

if (!$result['success']) {
    echo "ERROR: " . $result['error'] . "\n";
    exit(1);
}

echo "Archivo: " . $result['source_file'] . "\n";
echo "Origen:  " . $result['data_source'] . "\n";
echo "Modificado: " . $result['modified'] . "\n";
echo "Registros: " . $result['records'] . "\n";

$cleanup = cleanupOldBackups(7);
if ($cleanup['deleted'] > 0) {
    echo "Limpieza: {$cleanup['deleted']} respaldo(s) intermedio(s) antiguo(s) eliminados.\n";
}

echo "\nMonitor ejecutado correctamente.\n";
exit(0);
