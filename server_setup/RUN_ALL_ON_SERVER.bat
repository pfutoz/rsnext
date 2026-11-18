@echo off
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0run_all_setup.ps1"
if errorlevel 1 (
    echo [FAILED] Setup stopped with an error.
    pause
    exit /b 1
)
pause
