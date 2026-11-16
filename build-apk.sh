#!/usr/bin/env bash
# ====================================================================
# ISP Billing Android APK Build Script (Linux / macOS)
# ====================================================================

set -e

echo "===================================================================="
echo "  Building ISP Billing Android APK (Debug)"
echo "===================================================================="

if [ -f "./gradlew" ]; then
    chmod +x ./gradlew
    ./gradlew assembleDebug
elif command -v gradle &> /dev/null; then
    gradle :app:assembleDebug
else
    echo "[ERROR] Neither ./gradlew nor gradle command was found!"
    echo "Please install Java and Gradle or open the project in Android Studio."
    exit 1
fi

echo ""
echo "===================================================================="
echo "  [SUCCESS] APK Built Successfully!"
echo "  Output: app/build/outputs/apk/debug/app-debug.apk"
echo "===================================================================="
