@echo off
REM ---------------------------------------------------------------------------
REM LiquidityBuddy - local database setup for Windows (XAMPP / MariaDB)
REM
REM   scripts\setup-local-db.bat            back up + migrate the existing database
REM   scripts\setup-local-db.bat fresh      DROP + reload the demo dump, then migrate
REM                                         (also makes the dump's ungrouped accounts visible)
REM
REM Edit the settings below if your MySQL path or credentials differ.
REM ---------------------------------------------------------------------------
setlocal EnableExtensions

set "MYSQL_BIN=C:\xampp\mysql\bin"
set "DB_HOST=localhost"
set "DB_PORT=3306"
set "DB_USER=root"
set "DB_PASS="
REM The dump and migration target the `liquiditybuddy` schema; keep this name
REM (or use scripts/setup-local-db.sh --db NAME from Git Bash, which retargets them).
set "DB_NAME=liquiditybuddy"

set "ROOT=%~dp0.."
set "DUMP=%ROOT%\DBBackup\Liquidity_Buddy.sql"
set "MIGRATION=%ROOT%\DBBackup\Migrations\2026_10_02_account_payment_methods.sql"
set "GROUP_ORPHANS=%ROOT%\scripts\sql\dev_group_orphan_accounts.sql"
set "BACKUP_DIR=%ROOT%\writable\backups"

if not exist "%MYSQL_BIN%\mysql.exe" (
    echo mysql.exe not found in %MYSQL_BIN% - edit MYSQL_BIN at the top of this script.
    exit /b 1
)

REM Password via environment variable so it does not appear in the process list
set "MYSQL_PWD=%DB_PASS%"
set "CONN=-h %DB_HOST% -P %DB_PORT% -u %DB_USER%"

echo ==^> Connecting to %DB_USER%@%DB_HOST%:%DB_PORT% (database: %DB_NAME%)
"%MYSQL_BIN%\mysql.exe" %CONN% -N -e "SELECT VERSION()" || (
    echo Cannot connect - is MySQL running in the XAMPP control panel? Check the settings above.
    exit /b 1
)

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set "STAMP=%%i"
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

"%MYSQL_BIN%\mysql.exe" %CONN% -N -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='%DB_NAME%'" > "%TEMP%\lb_dbexists.txt"
set /p DB_EXISTS=<"%TEMP%\lb_dbexists.txt"
del "%TEMP%\lb_dbexists.txt" >nul 2>&1

if /i "%~1"=="fresh" goto fresh

if not "%DB_EXISTS%"=="1" (
    echo Database %DB_NAME% does not exist. Run: scripts\setup-local-db.bat fresh
    exit /b 1
)
echo ==^> Backing up %DB_NAME% to writable\backups\%DB_NAME%_before_payment_methods_%STAMP%.sql
"%MYSQL_BIN%\mysqldump.exe" %CONN% --routines --triggers --databases %DB_NAME% > "%BACKUP_DIR%\%DB_NAME%_before_payment_methods_%STAMP%.sql" || exit /b 1
goto migrate

:fresh
if "%DB_EXISTS%"=="1" (
    echo.
    echo This will DROP database %DB_NAME% and reload the demo dump.
    set /p CONFIRM=Type the database name to continue:
    call :check_confirm || exit /b 1
    echo ==^> Backing up current database first
    "%MYSQL_BIN%\mysqldump.exe" %CONN% --routines --triggers --databases %DB_NAME% > "%BACKUP_DIR%\%DB_NAME%_before_fresh_%STAMP%.sql" || exit /b 1
)
echo ==^> Recreating %DB_NAME% from DBBackup\Liquidity_Buddy.sql
"%MYSQL_BIN%\mysql.exe" %CONN% -e "DROP DATABASE IF EXISTS %DB_NAME%" || exit /b 1
"%MYSQL_BIN%\mysql.exe" %CONN% < "%DUMP%" || exit /b 1

:migrate
echo ==^> Applying DBBackup\Migrations\2026_10_02_account_payment_methods.sql
"%MYSQL_BIN%\mysql.exe" %CONN% < "%MIGRATION%" || exit /b 1

if /i "%~1"=="fresh" (
    echo ==^> Assigning ungrouped demo accounts to an account group
    "%MYSQL_BIN%\mysql.exe" %CONN% < "%GROUP_ORPHANS%" || exit /b 1
)

echo ==^> Checking the result
"%MYSQL_BIN%\mysql.exe" %CONN% -t %DB_NAME% -e "SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('paymentmethod','payment','duepaid')) AS new_tables_of_3, (SELECT COUNT(*) FROM paymentmethod WHERE status='A') AS active_payment_methods, (SELECT COUNT(*) FROM account a WHERE a.status='A' AND a.accountgroupid IS NULL) AS hidden_ungrouped_accounts;"

echo.
echo Done. Next:  composer install   then   php spark serve   and open http://localhost:8080
echo See LOCAL_SETUP.md for the walkthrough of the payment features.
exit /b 0

:check_confirm
if /i "%CONFIRM%"=="%DB_NAME%" exit /b 0
echo Aborted.
exit /b 1
