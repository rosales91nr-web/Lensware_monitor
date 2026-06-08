# Lensware Pro — Monitor de Producción

Dashboard web para monitorear en tiempo real la producción de lentes en Lensware. Lee archivos CSV exportados por el sistema Lensware y muestra KPIs, gráficos por estado/hora/dispositivo/operador y gestión de respaldos históricos.

## Stack

- **Backend**: PHP 8.2 (sin frameworks, sin Composer)
- **Frontend**: HTML + CSS + Vanilla JS + Chart.js (CDN)
- **Servidor**: PHP built-in server (`php -S 0.0.0.0:5000 router.php`)
- **Datos**: Archivos CSV procesados a JSON cache

## Estructura

```
/
├── index.php              # SPA principal (login + dashboard)
├── api.php                # REST API (upload, sync, backups, files)
├── config.php             # Configuración multi-entorno (Replit / Windows)
├── router.php             # Router de seguridad para php -S
├── monitor.php            # Monitor de sincronización (corre en background)
├── includes/
│   ├── functions.php      # Lógica de procesamiento CSV y backups
│   └── backup_manager.php # Gestión de respaldos históricos
├── js/app.js              # Lógica frontend (charts, estado, API calls)
├── push_to_replit.ps1     # Script PowerShell: sube CSV desde Windows → Replit
├── data/                  # Almacenamiento persistente en Replit (gitignored)
│   ├── staging/           # CSVs subidos pendientes de procesar
│   ├── backups/           # Respaldos históricos diarios
│   ├── cache/             # Cache JSON del estado procesado
│   └── logs/              # Logs de la aplicación
└── docker/                # Config Docker/Railway (no usada en Replit)
```

## Cómo correr en Replit

El workflow "Start application" hace todo automáticamente:
```bash
APP_ENV=railway TMP_BASE=/home/runner/workspace/data \
  bash -c 'php monitor.php 2>&1; while true; do sleep 60 && php monitor.php 2>&1; done & php -S 0.0.0.0:5000 router.php'
```

- El servidor PHP escucha en el puerto 5000
- `monitor.php` corre cada 60 segundos en background para procesar CSVs nuevos
- Todos los datos se guardan en `./data/` (persistente en Replit, no en /tmp)

## Variables de entorno / Secrets

| Variable        | Dónde       | Descripción                                              |
|-----------------|-------------|----------------------------------------------------------|
| `APP_ENV`       | workflow    | Siempre `railway` en Replit                              |
| `TMP_BASE`      | workflow    | Ruta base de datos: `/home/runner/workspace/data`        |
| `UPLOAD_SECRET` | Replit Secret | Token para subida externa desde PowerShell             |

## Flujo de uso

### Opción A — Subida manual (web)
1. Exportá el CSV desde Lensware (prefijo `UNI_PROD_ALL_ACT_*` o `UNI_PROD_SIMPLE_ACT_*`)
2. Abrí el dashboard → pestaña **Importar CSV**
3. Arrastrá o seleccioná el archivo → "Subir y procesar"
4. El dashboard se actualiza automáticamente

### Opción B — Subida automática desde Windows (PowerShell)
1. Configurá `$REPLIT_URL` y `$UPLOAD_SECRET` en `push_to_replit.ps1`
2. Programá el script con el Programador de tareas de Windows cada 1-5 minutos
3. El script busca el CSV más reciente en la carpeta de Lensware y lo sube

## Login

Clave maestra por defecto: `JimLab*Lensware#_`

## User preferences

- Idioma de respuestas: **Español**
- Mantener compatibilidad con Railway (no romper el Dockerfile)
- Datos persistentes en `./data/` (no en /tmp)
