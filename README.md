# Lensware Pro — Monitor de Producción

Dashboard web en tiempo real para monitorear la producción de lentes en Lensware. Procesa archivos CSV exportados por el sistema y muestra KPIs, gráficos por estado/hora/dispositivo/operador y gestión de respaldos históricos.

**Plataforma principal: [Replit](https://replit.com)**

## Inicio rápido en Replit

El proyecto está listo para correr sin configuración adicional:

1. Abrí el Repl
2. El workflow "Start application" arranca automáticamente
3. Accedé al dashboard en el Preview — clave: `JimLab*Lensware#_`

## Cómo subir datos

### Opción A — Subida manual (web)

1. Iniciá sesión en el dashboard
2. Pestaña **Importar CSV**
3. Arrastrá o seleccioná el archivo CSV de Lensware (`UNI_PROD_ALL_ACT_*` o `UNI_PROD_SIMPLE_ACT_*`)
4. El dashboard se actualiza automáticamente al procesar

### Opción B — Script automático desde Windows (PowerShell)

Usá `push_to_replit.ps1` para subir el CSV más reciente desde la carpeta de Lensware:

```powershell
# Editar estas variables en el script:
$REPLIT_URL    = "https://TU-APP.replit.app/api.php"
$UPLOAD_SECRET = "tu_token_secreto"
$REPORTS_FOLDER = "\\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS"

# Correr manualmente:
.\push_to_replit.ps1

# O programar con el Programador de tareas de Windows (cada 2 min):
# Acción: powershell.exe -ExecutionPolicy Bypass -File "C:\ruta\push_to_replit.ps1"
```

## Variables de entorno

Configuradas en Replit Secrets (no en código):

| Variable        | Descripción                                              |
|-----------------|----------------------------------------------------------|
| `UPLOAD_SECRET` | Token para autenticar subidas externas desde PowerShell  |

Variables de workflow (ya configuradas, no editar):

| Variable   | Valor                              |
|------------|------------------------------------|
| `APP_ENV`  | `railway`                          |
| `TMP_BASE` | `/home/runner/workspace/data`      |

## Estructura del proyecto

```
/
├── index.php              # SPA: login + dashboard completo
├── api.php                # REST API interna
├── config.php             # Configuración multi-entorno
├── router.php             # Seguridad para php -S (bloquea dirs sensibles)
├── monitor.php            # Sincronización periódica (corre en background)
├── cleanup.php            # Limpieza de backups (1 por día)
├── process_daily_backups.php # CLI para procesar backups
├── push_to_replit.ps1     # Script Windows → sube CSV automáticamente
├── includes/
│   ├── functions.php      # Lógica de datos, CSV, backups, sync
│   └── backup_manager.php # Gestión de respaldos históricos
├── js/app.js              # Frontend (charts, tabs, API calls)
└── data/                  # Almacenamiento persistente (gitignored)
    ├── staging/           # CSVs recibidos
    ├── backups/           # Respaldos históricos diarios
    ├── cache/             # Cache JSON procesado + metadatos de sync
    └── logs/              # Logs de la aplicación
```

## API

| Acción               | Método | Descripción                                      |
|----------------------|--------|--------------------------------------------------|
| `?action=data`       | GET    | Datos en vivo del dashboard                      |
| `?action=status`     | GET    | Estado del sistema, última sync, disco, logs     |
| `?action=sync`       | POST   | Forzar sincronización                            |
| `?action=upload_csv` | POST   | Subir CSV (requiere `X-Upload-Secret` header)    |
| `?action=backups`    | GET    | Listado de respaldos históricos                  |

## Despliegue

Para publicar en Replit, usá el botón **Publish** en la interfaz. El proyecto está configurado para `autoscale`.

---

Desarrollado por Nestor Rosales | Rosalesdev91
