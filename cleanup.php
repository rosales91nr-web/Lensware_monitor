<?php
// cleanup.php - Limpieza de backups del volumen Railway
// Conserva exactamente 1 backup por día (el _2359_ si existe, o el más reciente).
// Ejecutar manualmente: php cleanup.php
// O desde el dashboard vía: GET /api.php?action=cleanup_backups&secret=TU_SECRET

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

/**
 * Limpieza principal: 1 backup por día basado en la fecha detectada en el CSV.
 *
 * Regla de selección por día:
 *   1. Si existe un backup _2359_ para ese día → ese es el oficial (snapshot fin de día).
 *   2. Si no, el backup más reciente (mayor filemtime) de ese día.
 *   3. Todo lo demás del mismo día → eliminado.
 *
 * @param  bool $dryRun  true = solo reportar, no borrar nada.
 * @return array  Resultado con claves: kept, deleted, freed_bytes, files_deleted, detail.
 */
function cleanupBackupsOnePerDay(bool $dryRun = false): array
{
    $dir = BACKUP_FOLDER;

    if (!is_dir($dir)) {
        return [
            'kept'          => 0,
            'deleted'       => 0,
            'freed_bytes'   => 0,
            'files_deleted' => [],
            'detail'        => [],
            'error'         => 'BACKUP_FOLDER no existe: ' . $dir,
        ];
    }

    $files = glob($dir . '/BACKUP_*.csv') ?: [];

    // -------------------------------------------------------------------------
    // Agrupar archivos por fecha (YYYYMMDD extraída del nombre BACKUP_YYYYMMDD_...)
    // -------------------------------------------------------------------------
    $byDate = [];   // [ 'YYYY-MM-DD' => [ ['file'=>path, 'is_daily'=>bool, 'mtime'=>int], ... ] ]

    foreach ($files as $f) {
        $name = basename($f);

        // Extraer fecha del nombre: BACKUP_20250519_143022_UNI_PROD...csv
        if (preg_match('/^BACKUP_(\d{4})(\d{2})(\d{2})_/', $name, $m)) {
            $dateKey = "{$m[1]}-{$m[2]}-{$m[3]}";
        } else {
            // Fallback: fecha de modificación del archivo
            $dateKey = date('Y-m-d', filemtime($f));
        }

        $byDate[$dateKey][] = [
            'file'     => $f,
            'name'     => $name,
            'is_daily' => str_contains($name, '_2359_'),
            'mtime'    => filemtime($f),
            'size'     => filesize($f),
        ];
    }

    krsort($byDate); // más recientes primero (útil para el informe)

    $keptTotal    = 0;
    $deletedTotal = 0;
    $freedBytes   = 0;
    $filesDeleted = [];
    $detail       = [];

    foreach ($byDate as $date => $group) {
        // -----------------------------------------------------------------
        // Elegir el backup oficial para este día
        // -----------------------------------------------------------------
        $official = null;

        // Prioridad 1: backup de fin de día (_2359_)
        foreach ($group as $entry) {
            if ($entry['is_daily']) {
                $official = $entry;
                break;
            }
        }

        // Prioridad 2: el más reciente del día
        if ($official === null) {
            usort($group, fn($a, $b) => $b['mtime'] - $a['mtime']);
            $official = $group[0];
        }

        $dayKept    = 1;
        $dayDeleted = 0;
        $dayFreed   = 0;
        $dayRemoved = [];

        foreach ($group as $entry) {
            if ($entry['file'] === $official['file']) {
                continue; // conservar este
            }

            // Eliminar
            if (!$dryRun) {
                if (@unlink($entry['file'])) {
                    logMessage("cleanup: eliminado {$entry['name']}");
                } else {
                    logMessage("cleanup: no se pudo eliminar {$entry['name']}", 'error');
                    continue; // no contar como eliminado si falló
                }
            }

            $dayDeleted++;
            $dayFreed   += $entry['size'];
            $dayRemoved[] = $entry['name'];
            $filesDeleted[] = $entry['name'];
        }

        $keptTotal    += $dayKept;
        $deletedTotal += $dayDeleted;
        $freedBytes   += $dayFreed;

        $detail[] = [
            'date'      => $date,
            'kept'      => $official['name'],
            'is_daily'  => $official['is_daily'],
            'deleted'   => $dayDeleted,
            'freed_kb'  => round($dayFreed / 1024, 1),
            'removed'   => $dayRemoved,
        ];
    }

    return [
        'dry_run'       => $dryRun,
        'dates_found'   => count($byDate),
        'kept'          => $keptTotal,
        'deleted'       => $deletedTotal,
        'freed_bytes'   => $freedBytes,
        'freed_mb'      => round($freedBytes / 1024 / 1024, 2),
        'files_deleted' => $filesDeleted,
        'detail'        => $detail,
    ];
}

// =============================================================================
// EJECUCIÓN DIRECTA POR CLI
// =============================================================================
if (PHP_SAPI === 'cli') {
    $dryRun = in_array('--dry-run', $argv ?? [], true);

    echo "===========================================\n";
    echo "Lensware Pro - Limpieza de Backups\n";
    echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
    echo ($dryRun ? "** MODO DRY-RUN (sin borrar nada) **\n" : "** MODO REAL (borrando archivos) **\n");
    echo "===========================================\n\n";

    $result = cleanupBackupsOnePerDay($dryRun);

    if (!empty($result['error'])) {
        echo "ERROR: {$result['error']}\n";
        exit(1);
    }

    echo "Días con backups encontrados : {$result['dates_found']}\n";
    echo "Backups conservados          : {$result['kept']}  (1 por día)\n";
    echo "Backups eliminados           : {$result['deleted']}\n";
    echo "Espacio liberado             : {$result['freed_mb']} MB\n\n";

    if (!empty($result['detail'])) {
        echo "Detalle por día:\n";
        printf("  %-12s  %-8s  %-55s  %s\n", 'Fecha', 'Tipo', 'Conservado', 'Eliminados');
        echo "  " . str_repeat('-', 100) . "\n";
        foreach ($result['detail'] as $d) {
            $tipo = $d['is_daily'] ? '23:59   ' : 'reciente';
            printf(
                "  %-12s  %-8s  %-55s  %d archivo(s) · %.1f KB\n",
                $d['date'],
                $tipo,
                substr($d['kept'], 0, 55),
                $d['deleted'],
                $d['freed_kb']
            );
        }
    }

    echo "\nLimpieza completada.\n";
    exit(0);
}
