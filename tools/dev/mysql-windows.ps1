<#
  Moteur MySQL 8.4 JETABLE pour les tests, sans Docker : equivalent Windows de tools/dev/mysql-up.sh.

  Demarre (ou reutilise) un MySQL Community 8.4 installe sur le poste, dans son propre repertoire de donnees,
  sur 127.0.0.1 uniquement, et prepare la base isolee des tests : base "eeo_test", utilisateur "eeo_test" limite
  aux bases de test jetables ("eeo_*_test"). Aucune instance MySQL existante n'est utilisee ni modifiee : le
  moteur a son port (3316 par defaut), ses donnees et son journal.

  Usage (depuis la racine du depot) :
    powershell -ExecutionPolicy Bypass -File tools\dev\mysql-windows.ps1                 # demarre / prepare
    powershell -ExecutionPolicy Bypass -File tools\dev\mysql-windows.ps1 -Action status
    powershell -ExecutionPolicy Bypass -File tools\dev\mysql-windows.ps1 -Action stop

  Puis, dans le shell qui lance les tests (Git Bash) :
    export EEO_TEST_DB_PORT=3316        # les autres valeurs par defaut (127.0.0.1, eeo_test) conviennent
    tools/dev/check-all.sh --strict

  Reglages "moteur de test" : durabilite relachee (innodb_flush_log_at_trx_commit=0, sync_binlog=0, pas de
  double ecriture). Sur un disque lent, chaque ALTER TABLE ... ADD FOREIGN KEY de la baseline (464) coutait
  plusieurs secondes a cause des fsync ; ces reglages divisent la migration par plusieurs fois. Ils sont
  acceptables parce que les donnees sont fictives et jetables, et INTERDITS pour toute base qui garde des donnees
  reelles. log_bin_trust_function_creators=1 est requis pour creer les triggers de protection de l'historique
  quand le journal binaire est actif (voir docs/implementation/hosting-audit.md, point 8).

  Aucun mot de passe n'est ecrit dans le depot : le mot de passe de la base de test est la valeur fictive de
  tools/dev/mysql-up.sh, utilisable seulement sur ce moteur local lie a 127.0.0.1. Le compte root du moteur n'a
  pas de mot de passe (--initialize-insecure) : il n'est joignable que depuis ce poste.
#>
[CmdletBinding()]
param(
    [ValidateSet('start', 'stop', 'status')]
    [string]$Action = 'start',
    [string]$Root = (Join-Path $env:LOCALAPPDATA 'eeo-mysql-test'),
    [int]$Port = 3316,
    [string]$Basedir = 'C:\Program Files\MySQL\MySQL Server 8.4',
    [string]$TestDatabase = 'eeo_test',
    [string]$TestUser = 'eeo_test',
    [string]$TestPassword = 'eeo_test_password'
)

# Les commandes natives (mysqladmin, mysql, mysqld) signalent leurs echecs par leur code de sortie, jamais par
# une exception : "Continue" evite que Windows PowerShell 5.1 transforme leur sortie d'erreur en erreur fatale
# (un "ping" sur un serveur pas encore demarre est un cas normal). Les cmdlets qui doivent echouer le disent.
$ErrorActionPreference = 'Continue'

function Fail([string]$Message) {
    Write-Error "[mysql-windows] ERREUR : $Message"
    exit 1
}

function Log([string]$Message) {
    Write-Host "[mysql-windows] $Message"
}

# Les identifiants sont inseres dans du SQL : jeu de caracteres sur, comme dans mysql-up.sh.
if ($TestDatabase -notmatch '^[a-z0-9][a-z0-9_]*_test$') { Fail "TestDatabase doit se terminer par _test (recu : $TestDatabase)." }
if ($TestUser -notmatch '^[a-z0-9_]+$') { Fail 'TestUser ne doit contenir que [a-z0-9_].' }
if ($TestPassword -notmatch '^[A-Za-z0-9_.-]+$') { Fail 'TestPassword ne doit contenir que [A-Za-z0-9_.-].' }
if ($Port -lt 1024 -or $Port -gt 65535) { Fail 'Port invalide.' }

$mysqld = Join-Path $Basedir 'bin\mysqld.exe'
$mysql = Join-Path $Basedir 'bin\mysql.exe'
$mysqladmin = Join-Path $Basedir 'bin\mysqladmin.exe'
foreach ($binary in @($mysqld, $mysql, $mysqladmin)) {
    if (-not (Test-Path $binary)) { Fail "Introuvable : $binary (installer MySQL Community 8.4 ou passer -Basedir)." }
}

$dataDir = Join-Path $Root 'data'
$ini = Join-Path $Root 'my.ini'
$errorLog = Join-Path $Root 'mysqld.err'
$pidFile = Join-Path $Root 'mysqld.pid.txt'

# ATTENTION : PowerShell 5.1 decoupe "-h127.0.0.1" en "-h127" et ".0.0.1" ; toujours des options longues "--host=".
$connection = @('--protocol=tcp', '--host=127.0.0.1', "--port=$Port")

function Test-Alive {
    & $mysqladmin @connection --user=root ping 2>&1 | Out-Null
    return ($LASTEXITCODE -eq 0)
}

function Invoke-AsRoot([string]$Sql) {
    $Sql | & $mysql @connection --user=root
    if ($LASTEXITCODE -ne 0) { Fail 'Requete refusee par MySQL (voir ci-dessus).' }
}

if ($Action -eq 'status') {
    if (Test-Alive) {
        $version = & $mysql @connection --user=root -N -B -e 'SELECT VERSION()' 2>&1
        Log "Moteur de test joignable sur 127.0.0.1:$Port (MySQL $version)."
    } else {
        Log "Aucun moteur de test sur 127.0.0.1:$Port."
        exit 3
    }
    exit 0
}

if ($Action -eq 'stop') {
    if (Test-Alive) {
        & $mysqladmin @connection --user=root shutdown 2>&1 | Out-Null
        Log 'Arret demande.'
    } else {
        Log 'Deja arrete.'
    }
    exit 0
}

# --- start -----------------------------------------------------------------------------------------------------
New-Item -ItemType Directory -Force -Path $Root -ErrorAction Stop | Out-Null
$iniText = @"
[mysqld]
port=$Port
bind-address=127.0.0.1
mysqlx=OFF
basedir=$($Basedir -replace '\\', '/')
datadir=$($dataDir -replace '\\', '/')
log_bin_trust_function_creators=1
character-set-server=utf8mb4
collation-server=utf8mb4_0900_ai_ci
# Moteur de test JETABLE : durabilite inutile, vitesse recherchee.
innodb_flush_log_at_trx_commit=0
sync_binlog=0
innodb_doublewrite=OFF
innodb_buffer_pool_size=512M
"@
Set-Content -Path $ini -Value $iniText -Encoding ascii -ErrorAction Stop

if (Test-Alive) {
    Log "Moteur deja demarre sur 127.0.0.1:$Port : reutilisation."
} else {
    if (-not (Test-Path (Join-Path $dataDir 'mysql'))) {
        Log "Initialisation du repertoire de donnees ($dataDir) : quelques minutes sur un disque lent."
        & $mysqld "--defaults-file=$ini" --initialize-insecure --console 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { Fail "Initialisation impossible (voir $errorLog ou relancer sans -Root existant)." }
    }

    $process = Start-Process -FilePath $mysqld -ArgumentList @("--defaults-file=`"$ini`"", "--log-error=`"$errorLog`"") -WindowStyle Hidden -PassThru -ErrorAction Stop
    Set-Content -Path $pidFile -Value $process.Id -ErrorAction Stop
    Log "mysqld lance (PID $($process.Id)) ; attente de la disponibilite."

    $ready = $false
    for ($attempt = 0; $attempt -lt 150; $attempt++) {
        Start-Sleep -Seconds 2
        if ($process.HasExited) { Fail "mysqld s'est arrete (voir $errorLog)." }
        if (Test-Alive) { $ready = $true; break }
    }
    if (-not $ready) { Fail "MySQL ne repond pas apres 5 minutes (voir $errorLog)." }
}

Log "Preparation de la base isolee $TestDatabase et de l'utilisateur $TestUser."
Invoke-AsRoot @"
SET GLOBAL log_bin_trust_function_creators = 1;
CREATE DATABASE IF NOT EXISTS ``$TestDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '$TestUser'@'%' IDENTIFIED BY '$TestPassword';
ALTER USER '$TestUser'@'%' IDENTIFIED BY '$TestPassword';
GRANT ALL PRIVILEGES ON ``$TestDatabase``.* TO '$TestUser'@'%';
GRANT ALL PRIVILEGES ON ``eeo\_%\_test``.* TO '$TestUser'@'%';
"@

$env:MYSQL_PWD = $TestPassword
try {
    $check = & $mysql @connection "--user=$TestUser" $TestDatabase -N -B -e 'SELECT VERSION()' 2>&1
} finally {
    $env:MYSQL_PWD = ''
}
if ($LASTEXITCODE -ne 0) { Fail 'Connexion de test impossible apres preparation.' }
Log "Connexion de test verifiee : MySQL $check, base $TestDatabase."

Write-Host ''
Write-Host 'Pret. Dans le shell qui lance les tests :'
Write-Host "  export EEO_TEST_DB_PORT=$Port"
Write-Host '  tools/dev/check-all.sh --strict'
