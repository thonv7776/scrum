@echo off
setlocal
cd /d "%~dp0"
set "PHC_PHP="
for /d %%V in ("C:\wamp64\bin\php\php*") do if exist "%%~fV\php.exe" set "PHC_PHP=%%~fV\php.exe"
if not defined PHC_PHP if exist "C:\xampp\php\php.exe" set "PHC_PHP=C:\xampp\php\php.exe"
if not defined PHC_PHP (
 echo Could not find PHP.exe. Use browser: http://localhost/PrivateHire_Cars_Sprint1/sprint2_status.php
 pause
 exit /b 1
)
echo Using PHP: %PHC_PHP%
"%PHC_PHP%" -m | findstr /i pdo_mysql
if errorlevel 1 echo WARNING: pdo_mysql extension not enabled in this PHP.
"%PHC_PHP%" tests\test_logic.php
"%PHC_PHP%" tests\test_sprint2_logic.php
pause
