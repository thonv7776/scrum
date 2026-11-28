@echo off
setlocal
cd /d "%~dp0"
PowerShell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Controllers\Setup\Start.ps1" -Root "%~dp0."
if errorlevel 1 (
  echo.
  echo Startup failed. See the message above and README.md.
  pause
)
endlocal
