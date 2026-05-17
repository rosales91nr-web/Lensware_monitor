# Lensware Pro — Railway Deploy

Dashboard PHP para monitorear producción de Lensware, adaptado para ejecutarse en [Railway](https://railway.app).

---

## Arquitectura

```
Windows Server (Lensware)          Railway (contenedor Linux)
┌─────────────────────────┐        ┌──────────────────────────┐
│  REPORTS/*.csv          │        │  /uploads/*.csv          │
│  push_csv.ps1 (cron)   │──POST──▶│  api.php?action=upload_csv│
│  cada 5 min             │        │  → cache/data.json       │
└─────────────────────────┘        │  → Dashboard (index.php) │
                                   └──────────────────────────┘
```

> Railway es Linux y no puede acceder a rutas UNC de Windows (`\\servidor\share`).  
> La solución es un script PowerShell en el servidor Windows que **empuja** el CSV via HTTP POST.

---

## Deploy en Railway

### 1. Subir a GitHub

```bash
git init
git add .
git commit -m "Initial commit"
git remote add origin https://github.com/TU_USUARIO/lensware-pro.git
git push -u origin main
```

### 2. Crear proyecto en Railway

1. Ir a [railway.app](https://railway.app) → **New Project** → **Deploy from GitHub repo**
2. Seleccionar el repo `lensware-pro`
3. Railway detectará el `Dockerfile` automáticamente

### 3. Configurar variables de entorno

En Railway → tu proyecto → **Variables**, añadir:

| Variable        | Valor                          |
|-----------------|-------------------------------|
| `UPLOAD_SECRET` | (clave segura, mínimo 20 chars)|
| `CACHE_TTL`     | `60`                          |

### 4. Configurar el script Windows

Editar `push_csv.ps1`:

```powershell
$RailwayUrl   = "https://TU-APP.up.railway.app/api.php?action=upload_csv"
$UploadSecret = "la_misma_clave_que_en_railway"
$WatchFolder  = "\\172.16.8.32\Lensware\LensSOAPServer_INT\www\REPORTS"
```

Crear tarea programada en Windows:
```
Programa: powershell.exe
Argumentos: -ExecutionPolicy Bypass -File "C:\ruta\push_csv.ps1"
Frecuencia: cada 5 minutos
```

---

## Endpoints API

| Endpoint | Descripción |
|----------|-------------|
| `GET /api.php?action=status` | Estado del servidor |
| `GET /api.php?action=data` | Todos los registros (usa caché) |
| `GET /api.php?action=refresh` | Forzar recarga del caché |
| `GET /api.php?action=device&name=X` | Detalle de dispositivo |
| `GET /api.php?action=backups` | Listar respaldos |
| `GET /api.php?action=export&type=activity` | Exportar CSV actividad |
| `GET /api.php?action=export&type=breakages` | Exportar CSV quiebras |
| `POST /api.php?action=upload_csv` | Subir CSV desde Windows |

### Subida de CSV (desde Windows)

```
POST /api.php?action=upload_csv
Header: X-Upload-Secret: TU_UPLOAD_SECRET
Body: multipart/form-data
  csv_file: <archivo .csv>
```

---

## Estructura del proyecto

```
lensware-pro/
├── Dockerfile
├── railway.toml
├── docker/
│   ├── nginx.conf
│   └── supervisord.conf
├── config.php
├── api.php
├── index.php
├── app.js
├── styles.css
├── includes/
│   └── functions.php
├── monitor.php
├── push_csv.ps1          ← ejecutar en Windows
├── .env.example
└── .gitignore
```

---

## Notas importantes

- **Persistencia**: Railway reinicia el contenedor y borra `uploads/`, `cache/`, `backups/`.  
  Para persistir datos, añade uno o dos **Railway Volumes** montados en:  
  - `/var/www/html/uploads` para los CSVs entrantes  
  - `/var/www/html/backups` para los archivos de respaldo  
  En Railway → tu servicio → **Volumes** → Add Volume → mount path: `/var/www/html/uploads`  
  Repite para `/var/www/html/backups` o configura `BACKUP_FOLDER` si montas en otra ruta.

- **Logs**: disponibles en Railway → tu servicio → **Logs**

- **PHP**: versión 8.2 con opcache habilitado
