@echo off
rem Double-clic : demarre l'environnement local Zeno (PostgreSQL, migrations, serveur, scheduler).
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-zeno.ps1" %*
if errorlevel 1 pause
