<#
.SYNOPSIS
    Démarre l'environnement local Zeno : PostgreSQL 10, migrations, serveur Laravel et scheduler.

.DESCRIPTION
    1. Vérifie que PostgreSQL répond sur 127.0.0.1:5432 (sinon le démarre, étapes 2-3).
    2. Si aucun processus postgres n'utilise le dossier data, renomme un postmaster.pid orphelin.
    3. Lance pg_ctl start et vérifie le port 5432.
    4. php artisan config:clear + migrate --force.
    5. Libère le port 8000 puis lance « artisan serve » et « artisan schedule:work » dans deux fenêtres réduites.
    6. Ouvre l'application dans le navigateur par défaut.

    Usage : double-clic sur scripts\start-zeno.cmd
#>

$ErrorActionPreference = 'Stop'

$ProjectDir = Split-Path -Parent $PSScriptRoot
$PgBin      = 'C:\Program Files (x86)\PostgreSQL\10\bin'
$PgData     = 'C:\Program Files (x86)\PostgreSQL\10\data'
$PgPort     = 5432
$AppHost    = '127.0.0.1'
$AppPort    = 8000
$AppUrl     = "http://${AppHost}:$AppPort"
$PgLog      = Join-Path $ProjectDir 'storage\logs\pg10-start.log'
$SchedPid   = Join-Path $ProjectDir 'storage\logs\zeno-scheduler.pid'
$Shell      = (Get-Process -Id $PID).Path

function Write-Step([string]$Message) { Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok([string]$Message)   { Write-Host "    $Message" -ForegroundColor Green }
function Stop-WithError([string]$Message) {
    Write-Host ''
    Write-Host "ERREUR : $Message" -ForegroundColor Red
    exit 1
}

function Test-Port([int]$Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        return ($async.AsyncWaitHandle.WaitOne(1000) -and $client.Connected)
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Wait-Port([int]$Port, [int]$Seconds) {
    for ($i = 0; $i -lt $Seconds; $i++) {
        if (Test-Port $Port) { return $true }
        Start-Sleep -Seconds 1
    }
    return (Test-Port $Port)
}

function Test-IsAdmin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal $identity).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Test-DirWritable([string]$Dir) {
    $probe = Join-Path $Dir ".zeno-write-test-$PID"
    try {
        [IO.File]::WriteAllText($probe, 'x')
        Remove-Item $probe -Force
        return $true
    } catch {
        return $false
    }
}

# Un processus postgres utilise-t-il $PgData ?
# La ligne de commande des processus d'un service (autre compte) est illisible sans droits admin :
# on se rabat alors sur le PID inscrit dans postmaster.pid.
function Test-PgDataInUse {
    $dataNorm = ($PgData -replace '\\', '/').TrimEnd('/').ToLowerInvariant()
    $procs = @(Get-CimInstance Win32_Process -Filter "Name='postgres.exe'")

    foreach ($p in $procs) {
        if ($p.CommandLine -and ($p.CommandLine -replace '\\', '/').ToLowerInvariant().Contains($dataNorm)) {
            return $true
        }
    }

    $pidFile = Join-Path $PgData 'postmaster.pid'
    if (-not (Test-Path $pidFile)) { return $false }

    $lines = @(Get-Content $pidFile)
    $pgPid = 0
    if (-not [int]::TryParse("$($lines[0])".Trim(), [ref]$pgPid)) { return $false }

    $owner = $procs | Where-Object { $_.ProcessId -eq $pgPid }
    if (-not $owner) { return $false }
    if ($owner.CommandLine) { return $false } # lisible et ne cite pas $PgData : autre instance

    # Ligne de commande illisible : si ce PID écoute sur d'autres ports que celui du fichier,
    # c'est une autre instance (ex. PostgreSQL 18 sur 5433). Dans le doute, on considère « utilisé ».
    $filePort = 0
    [void][int]::TryParse("$($lines[3])".Trim(), [ref]$filePort)
    $ports = @(Get-NetTCPConnection -State Listen -OwningProcess $pgPid -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty LocalPort -Unique)
    if ($ports.Count -gt 0 -and $ports -notcontains $filePort) { return $false }
    return $true
}

Set-Location $ProjectDir

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Stop-WithError "php introuvable dans le PATH (Laragon est-il installé / démarré ?)."
}

# --- 1. PostgreSQL déjà démarré ? ---------------------------------------------------------
Write-Step "Vérification de PostgreSQL sur 127.0.0.1:$PgPort"
if (Test-Port $PgPort) {
    Write-Ok "PostgreSQL répond déjà."
} else {
    Write-Ok "Pas de réponse, démarrage de PostgreSQL 10."

    # --- 2. postmaster.pid orphelin ---------------------------------------------------------
    $pidFile = Join-Path $PgData 'postmaster.pid'
    if (Test-Path $pidFile) {
        if (Test-PgDataInUse) {
            Write-Host "    Un processus postgres utilise déjà $PgData : postmaster.pid conservé." -ForegroundColor Yellow
        } else {
            $orphan = "postmaster.pid.orphelin-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
            try {
                Rename-Item -Path $pidFile -NewName $orphan
                Write-Ok "postmaster.pid orphelin renommé en $orphan."
            } catch {
                Stop-WithError ("Impossible de renommer $pidFile ($($_.Exception.Message)).`n" +
                    "Relancez ce script en tant qu'administrateur (clic droit > Exécuter en tant qu'administrateur).")
            }
        }
    }

    # --- 3. pg_ctl start ---------------------------------------------------------------------
    Write-Step "pg_ctl start -D `"$PgData`" -w"
    # Fenêtre cachée dédiée : le serveur ne dépend pas de cette console (fermer la fenêtre ne l'arrête pas).
    $pgCtl = Start-Process -FilePath (Join-Path $PgBin 'pg_ctl.exe') `
        -ArgumentList @('start', '-D', "`"$PgData`"", '-w', '-l', "`"$PgLog`"") `
        -WindowStyle Hidden -PassThru
    [void]$pgCtl.Handle
    $pgCtl.WaitForExit()

    if (-not (Wait-Port $PgPort 15)) {
        $hint = if (Test-DirWritable $PgData) {
            "Les droits admin ne semblent pas en cause (dossier data accessible en écriture)."
        } elseif (Test-IsAdmin) {
            "Le dossier data n'est pas accessible en écriture, même en administrateur : vérifiez ses ACL."
        } else {
            "Le dossier data n'est pas accessible en écriture : relancez ce script en tant qu'administrateur."
        }
        Stop-WithError ("PostgreSQL ne répond pas sur le port $PgPort (code pg_ctl : $($pgCtl.ExitCode)).`n" +
            "$hint`nJournaux : $PgLog et $PgData\log")
    }
    Write-Ok "PostgreSQL 10 démarré."
}

# --- 4. Config + migrations ------------------------------------------------------------------
Write-Step "php artisan config:clear"
php artisan config:clear
if ($LASTEXITCODE -ne 0) { Stop-WithError "config:clear a échoué." }

Write-Step "php artisan migrate --force"
php artisan migrate --force
if ($LASTEXITCODE -ne 0) { Stop-WithError "Les migrations ont échoué." }

# --- 5. Serveur + scheduler -------------------------------------------------------------------
Write-Step "Libération du port $AppPort"
$listeners = @(Get-NetTCPConnection -State Listen -LocalPort $AppPort -ErrorAction SilentlyContinue |
    Select-Object -ExpandProperty OwningProcess -Unique | Where-Object { $_ -gt 0 })
foreach ($procId in $listeners) {
    $name = (Get-Process -Id $procId -ErrorAction SilentlyContinue).ProcessName
    # « artisan serve » (php -> cmd.exe -> php -S) relance le serveur sur le port suivant s'il meurt :
    # on remonte les ancêtres pour arrêter aussi le « artisan serve ».
    $ancestorId = $procId
    for ($depth = 0; $depth -lt 3; $depth++) {
        $ancestorId = (Get-CimInstance Win32_Process -Filter "ProcessId=$ancestorId" -ErrorAction SilentlyContinue).ParentProcessId
        if (-not $ancestorId) { break }
        $ancestor = Get-CimInstance Win32_Process -Filter "ProcessId=$ancestorId" -ErrorAction SilentlyContinue
        if ($ancestor -and $ancestor.CommandLine -like '*artisan*serve*') {
            taskkill /PID $ancestorId /T /F | Out-Null
            Write-Ok "Processus $ancestorId (artisan serve) arrêté."
            break
        }
    }
    Stop-Process -Id $procId -Force -ErrorAction SilentlyContinue
    Write-Ok "Processus $procId ($name) arrêté."
}
if ($listeners.Count -eq 0) { Write-Ok "Port libre." }

# Scheduler lancé par une exécution précédente de ce script : on l'arrête pour ne pas le doubler.
if (Test-Path $SchedPid) {
    $oldPid = "$(Get-Content $SchedPid -ErrorAction SilentlyContinue)".Trim()
    if ($oldPid -and (Get-Process -Id $oldPid -ErrorAction SilentlyContinue)) {
        taskkill /PID $oldPid /T /F | Out-Null
        Write-Ok "Ancien scheduler (PID $oldPid) arrêté."
    }
    Remove-Item $SchedPid -Force -ErrorAction SilentlyContinue
}

Write-Step "Lancement de php artisan serve et php artisan schedule:work (fenêtres réduites)"
$serveCmd = "`$Host.UI.RawUI.WindowTitle = 'Zeno - serveur'; php artisan serve --host=$AppHost --port=$AppPort"
$schedCmd = "`$Host.UI.RawUI.WindowTitle = 'Zeno - scheduler'; php artisan schedule:work"
Start-Process -FilePath $Shell -WorkingDirectory $ProjectDir -WindowStyle Minimized `
    -ArgumentList @('-NoProfile', '-Command', $serveCmd) | Out-Null
$sched = Start-Process -FilePath $Shell -WorkingDirectory $ProjectDir -WindowStyle Minimized `
    -ArgumentList @('-NoProfile', '-Command', $schedCmd) -PassThru
Set-Content -Path $SchedPid -Value $sched.Id

if (-not (Wait-Port $AppPort 20)) {
    Stop-WithError "Le serveur Laravel ne répond pas sur le port $AppPort (voir la fenêtre « Zeno - serveur »)."
}
Write-Ok "Serveur disponible sur $AppUrl"

# --- 6. Navigateur ----------------------------------------------------------------------------
Write-Step "Ouverture de $AppUrl"
Start-Process $AppUrl

Write-Host ''
Write-Host 'Zeno est démarré.' -ForegroundColor Green
