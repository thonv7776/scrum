@echo off
setlocal
cd /d "%~dp0"
set "PHC_PHP="
for /d %%V in ("C:\wamp64\bin\php\php*") do if exist "%%~fV\php.exe" set "PHC_PHP=%%~fV\php.exe"
if not defined PHC_PHP if exist "C:\xampp\php\php.exe" set "PHC_PHP=C:\xampp\php\php.exe"
if not defined PHC_PHP (
 echo PHP.exe not found in standard WAMP/XAMPP folders.
 pause
 exit /b 1
)
echo ===== PrivateHire Cars Sprint 2 - Create first Admin =====
echo NOTE: password is shown while typing in this basic local coursework prompt.
echo Close this window after finishing. Never share the password.
set /p "PHC_ADMIN_EMAIL=Admin email: "
set /p "PHC_ADMIN_NAME=Admin full name: "
set /p "PHC_ADMIN_PHONE=Phone, 10-11 digits: "
set /p "PHC_ADMIN_PASSWORD=New admin password (12+ chars, upper-case, digit): "
"%PHC_PHP%" sprint2_seed_admin.php
set "PHC_ADMIN_PASSWORD="
pause
