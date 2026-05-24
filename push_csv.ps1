# push_csv.ps1 — Alias de sync_local.ps1 (ya no sube a la nube; solo sincroniza local)
# Mantener en Programador de tareas si antes usabas este script.

& (Join-Path $PSScriptRoot "sync_local.ps1")
exit $LASTEXITCODE
