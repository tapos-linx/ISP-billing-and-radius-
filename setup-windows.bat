@echo off
REM ====================================================================
REM ISP Billing & Telecom BSS/OSS Console - Windows 1-Click Launcher
REM Author: Tapos (tapos.cpl@gmail.com)
REM ====================================================================

title ISP Billing - Windows Stack Launcher
echo ====================================================================
echo   ISP Billing: FreeRADIUS 3.2 + PostgreSQL 16 + Redis 7 on Windows
echo ====================================================================
echo.

echo Checking PowerShell...
where powershell >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo [ERROR] PowerShell is not found in PATH!
    pause
    exit /b 1
)

echo Launching automated setup via PowerShell...
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-windows.ps1"

if %ERRORLEVEL% neq 0 (
    echo.
    echo [WARNING] Setup script encountered an error or was interrupted.
) else (
    echo.
    echo [SUCCESS] Windows deployment completed successfully!
)

echo.
echo Press any key to exit...
pause >nul
