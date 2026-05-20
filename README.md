# Lensware Pro — XAMPP local

Dashboard de monitoreo de producción Lensware. Lee los CSV directamente desde la carpeta de red donde Lensware los genera.

## Carpeta de datos (REPORTS)

```
\\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS
```

Archivos esperados (prefijos):

- `UNI_PROD_ALL_ACT_*.csv`
- `UNI_PROD_SIMPLE_ACT_*.csv`

## Requisitos

- Windows con acceso a la ruta UNC anterior
- [XAMPP](https://www.apachefriends.org/) (PHP 8.x, Apache)
- Extensión PHP `mbstring` activada

## Instalación rápida

1. Copia el proyecto en `C:\xampp\htdocs\Lensware-pro`
2. Renombra o verifica que exista `.htaccess` (ya incluido)
3. Edita `.env` si la ruta REPORTS cambia en tu red
4. Inicia **Apache** en el panel XAMPP
5. Abre: **http://localhost/Lensware-pro/**

## Permisos importantes

Apache (usuario del servicio) debe poder **leer** la carpeta UNC REPORTS.

Opciones:

- Ejecutar Apache con una cuenta de Windows que tenga acceso al share
- O mapear la unidad de red (ej. `Z:\`) y poner en `.env`:
  ```
  WATCH_FOLDER=Z:\REPORTS
  ```

Las carpetas locales `cache/`, `backups/`, `uploads/`, `logs/` deben ser **escribibles** por Apache.

## Sincronización automática

### Opción A — Tarea programada (recomendada)

Ejecuta como administrador:

```
instalar_tarea_sync.bat
```

Esto crea la tarea **LenswarePro_Sync** que corre `sync_local.ps1` cada 2 minutos.

### Opción B — Monitor continuo

En PowerShell:

```powershell
cd C:\xampp\htdocs\Lensware-pro
.\monitor_local.ps1
```

### Opción C — Manual

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\Lensware-pro\monitor.php
```

El dashboard también actualiza solo al abrir la página y cada 30 segundos.

## Estructura local

| Carpeta | Uso |
|---------|-----|
| `\\172.16.8.32\...\REPORTS` | CSV en vivo (solo lectura) |
| `uploads/` | Importaciones manuales desde el navegador |
| `backups/` | Copias automáticas `BACKUP_*.csv` |
| `cache/` | Caché JSON del dashboard |
| `logs/` | Registro de la aplicación |

## Despliegue en Railway desde GitHub

Esta aplicación puede desplegarse en Railway usando el `Dockerfile` y el archivo `railway.toml` incluido.

1. Crea un repositorio en GitHub y sube este proyecto.
2. En Railway, crea un nuevo proyecto y conéctalo al repositorio de GitHub.
3. Configura el despliegue para usar el `Dockerfile` existente.
4. Ajusta las variables de entorno en Railway según sea necesario.

Variables recomendadas:

```env
REPORTS_FOLDER=\\172.16.8.32\\Lensware\\LensSOAPServer_INT\\www\\REPORTS
WATCH_FOLDER=\\172.16.8.32\\Lensware\\LensSOAPServer_INT\\www\\REPORTS
STAGING_FOLDER=/var/www/html/uploads
BACKUP_FOLDER=/var/www/html/backups
CACHE_TTL=30
BACKUP_RANGE_MAX_DAYS=93
UPLOAD_SECRET=tu_clave_secreta
APP_ENV=production
```

> Railway puede montar un volumen persistente en `/var/www/html/backups`. Si lo haces, la app guardará los backups ahí y los preservará entre despliegues.

> Nota: Railway ejecuta la aplicación en la nube dentro de un contenedor. Esto significa que una carpeta de red Windows (`\\host\\share`) no será accesible desde Railway a menos que esté disponible públicamente o mediante un montaje compatible.

Opciones en Railway:

- Si no puedes conectar el share remoto, usa la pestaña **Importar CSV** para subir archivos manualmente.
- Los directorios `cache/`, `backups/`, `logs/` y `uploads/` son temporales en el contenedor; si necesitas persistencia real, usa un almacenamiento externo.

También se incluye un flujo de despliegue automático en `.github/workflows/deploy-railway.yml`.

## Configuración (.env)

```env
REPORTS_FOLDER=\\172.16.8.32\\Lensware\\LensSOAPServer_INT\\www\\REPORTS
WATCH_FOLDER=\\172.16.8.32\\Lensware\\LensSOAPServer_INT\\www\\REPORTS
CACHE_TTL=30
```

## API local

| Acción | URL |
|--------|-----|
| Datos en vivo | `api.php?action=data` |
| Estado / REPORTS | `api.php?action=status` |
| Forzar sync | `api.php?action=sync` |
| Refrescar caché | `api.php?action=refresh` |

## Solución de problemas

**"REPORTS no accesible"**

- Abre la ruta en el Explorador de Windows desde la misma PC donde corre XAMPP
- Verifica credenciales del share
- Prueba mapear unidad de red y actualizar `.env`

**"No hay datos disponibles"**

- Confirma que exista un CSV con prefijo válido en REPORTS
- Ejecuta `php monitor.php` y revisa `logs/app.log`

**Apache no lee UNC**

- Usa `sync_local.ps1` en tarea programada con tu usuario de Windows (tiene acceso al share)
- El script PHP corre con tu usuario y actualiza la caché local

---

Desarrollado por Nestor Rosales | Rosalesdev91
