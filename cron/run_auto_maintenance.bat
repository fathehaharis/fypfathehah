@echo off
set "PHP_BIN=C:\xampp\php\php.exe"
set "SCRIPT=C:\xampp\htdocs\fypfathehah\cron\run_auto_maintenance.php"
set "LOG_DIR=C:\xampp\htdocs\fypfathehah\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
echo [%date% %time%] Running maintenance script...
"%PHP_BIN%" "%SCRIPT%" >> "%LOG_DIR%\auto_maintenance.log" 2>&1
set "RC=%ERRORLEVEL%"
echo [%date% %time%] Finished with exit code %RC%.
echo.
pause