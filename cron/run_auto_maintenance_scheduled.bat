@echo off
set "PHP_BIN=C:\xampp\php\php.exe"
set "SCRIPT=C:\xampp\htdocs\fypfathehah\cron\run_auto_maintenance.php"
set "LOG=C:\xampp\htdocs\fypfathehah\logs\auto_maintenance.log"
if not exist "C:\xampp\htdocs\fypfathehah\logs" mkdir "C:\xampp\htdocs\fypfathehah\logs"

"%PHP_BIN%" "%SCRIPT%" >> "%LOG%" 2>&1