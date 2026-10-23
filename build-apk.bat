@echo off
REM ====================================================================
REM ISP Billing Android APK Build Script (Windows)
REM ====================================================================

title Building ISP Billing Android APK
echo ====================================================================
echo   Building ISP Billing Android APK (Debug)
echo ====================================================================
echo.

if exist "gradlew.bat" (
    call .\gradlew.bat assembleDebug
) else if where gradle >nul 2>&1 (
    call gradle :app:assembleDebug
) else (
    echo [ERROR] Neither gradlew.bat nor gradle command was found!
    echo Please install Java and Gradle or open the project in Android Studio.
    pause
    exit /b 1
)

if %ERRORLEVEL% equ 0 (
    echo.
    echo ====================================================================
    echo   [SUCCESS] APK Built Successfully!
    echo   Output: app\build\outputs\apk\debug\app-debug.apk
    echo ====================================================================
) else (
    echo.
    echo [ERROR] Gradle build failed with exit code %ERRORLEVEL%.
)

echo.
echo Press any key to exit...
pause >nul
