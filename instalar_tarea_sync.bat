@echo off
REM Crea tarea programada: sincronizar REPORTS cada 2 minutos
set SCRIPT=%~dp0sync_local.ps1
schtasks /Create /TN "LenswarePro_Sync" /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"%SCRIPT%\"" /SC MINUTE /MO 2 /F
echo.
echo Tarea "LenswarePro_Sync" creada (cada 2 minutos).
echo Asegurate de que Apache/XAMPP este iniciado y que esta PC tenga acceso a REPORTS.
pause
