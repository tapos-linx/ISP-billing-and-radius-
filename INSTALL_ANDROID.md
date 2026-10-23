# Android Device Installation & Build Guide

This guide walks you through installing and running the **ISP Billing & Telecom Architect Console** on any Android device (phone, tablet, foldable, or emulator).

---

## 1. System Requirements & Compatibility

- **Minimum Android Version:** Android 7.0 (Nougat, API Level 24)
- **Target Android Version:** Android 14 / 15 / 16 (API Level 34–36)
- **Architectures Supported:** `arm64-v8a`, `armeabi-v7a`, `x86_64` (emulators & physical devices)
- **Design System:** Edge-to-Edge Material 3 with Dynamic Color theming
- **Device Support:** Handheld phones, foldables, and landscape tablets

---

## 2. Installation Options

You can install the app using any of the following 3 methods:

---

### Method A: Direct APK Sideloading (Easiest)

1. **Obtain the APK:**
   - In **Google AI Studio**: Open the top-right Settings menu (gear icon) and download the generated APK.
   - Or build locally using `build-apk.bat` (Windows) or `./build-apk.sh` (Linux/macOS). The APK file is located at:
     ```text
     app/build/outputs/apk/debug/app-debug.apk
     ```
2. **Transfer to your phone:**
   - Send the `app-debug.apk` file to your phone via USB cable, Google Drive, Telegram, WhatsApp, or local network share.
3. **Allow Installation from Unknown Sources:**
   - **Android 8.0 to Android 15:**
     1. Open your device's **Settings** → **Security & Privacy** (or **Apps** → **Special app access**).
     2. Tap **Install unknown apps**.
     3. Select the app you used to download or open the APK (e.g., *Chrome*, *Files by Google*, *Gmail*).
     4. Toggle **Allow from this source** to **ON**.
4. **Install the App:**
   - Open your Files app, tap `app-debug.apk`, and tap **Install**.
   - If Google Play Protect displays a prompt ("Unrecognized app"), tap **More details** → **Install anyway** (standard for debug builds).
5. **Launch:**
   - Find **ISP Billing** on your launcher and tap to open.

---

### Method B: Install via Android Debug Bridge (ADB)

If you have Android SDK Platform Tools installed on your computer:

1. Enable **Developer Options** and **USB Debugging** on your phone:
   - Go to **Settings** → **About phone**.
   - Tap **Build number** 7 times until you see "You are now a developer!".
   - Go to **Settings** → **System** → **Developer options** and toggle **USB debugging** to **ON**.
2. Connect your phone to your computer via USB.
3. Accept the prompt on your phone: *"Allow USB debugging from this computer?"*.
4. In your computer terminal (PowerShell, CMD, or Bash), navigate to this project folder and run:
   ```bash
   # Check that your device is detected
   adb devices

   # Install the debug APK
   adb install -r app/build/outputs/apk/debug/app-debug.apk
   ```
5. Launch the app directly from ADB:
   ```bash
   adb shell am start -n com.aistudio.ispbilling.txpkqr/com.example.MainActivity
   ```

---

### Method C: Build from Source in Android Studio

1. Open **Android Studio** (2023.3 Hedgehog or newer).
2. Click **File** → **Open...** and select this project directory.
3. Wait for Gradle sync to complete.
4. Select your target device (connected USB phone or Android Emulator) in the device dropdown.
5. Click **Run** (the green play icon `▶`) or press `Shift + F10`.

---

## 3. Command-Line APK Build

You can compile the Android application directly from terminal without opening Android Studio:

### On Windows:
```cmd
.\build-apk.bat
```
Or with PowerShell:
```powershell
.\gradlew.bat assembleDebug
```

### On Linux or macOS:
```bash
chmod +x ./build-apk.sh
./build-apk.sh
```
Or directly:
```bash
./gradlew assembleDebug
```

The compiled APK will be output to:
```text
app/build/outputs/apk/debug/app-debug.apk
```

---

## 4. App Features & Screen Navigation

Once installed on your Android device, you can access:

1. **28-Day Deployment Matrix (`StageMatrixScreen`):**
   - Interactive breakdown of Stages 1 through 5.
   - Inspect production-grade PHP, SQL, and YAML artifacts.
   - One-tap copy for all Windows PowerShell verification commands.
2. **Interactive Telecom Test Lab (`InteractiveToolsScreen`):**
   - **RFC 3576 PoD Disconnect:** Test session kick packets on UDP 3799 with real-time MD5 authenticator computation and `< 500ms` SLA gauge.
   - **bKash / Nagad Webhook Simulator:** Test HMAC-SHA256 signature verification and `< 3.0s` line unblock automation.
   - **FreeRADIUS Benchmark:** Run 100 authentication packets and visualize Min, Avg, and Max latency against `< 10ms` SLA.
   - **RouterOS v7 Script Generator:** Generate custom `.rsc` configurations for your specific VLAN, IP pool, and BRAS credentials.
3. **Subscribers & Accounting Console (`SubscriberConsoleScreen`):**
   - Live monitoring of PPPoE subscribers, tariffs, and monthly partitioned `radacct` records.
4. **PowerShell Script Vault (`PowerShellVaultScreen`):**
   - Production scripts for firewall hardening, daily MinIO S3 backups, and health monitoring.
5. **Dual AI Architect Assistant (`AiArchitectScreen`):**
   - Toggle between `gemini-3.1-flash-lite` (instant technical answers) and `gemini-4-argon` (deep telecom BGP/MPLS and database planning).

---

## 5. Troubleshooting Android Issues

### Issue 1: "App not installed" Error
- **Cause:** An older version of the app with conflicting signing keys is installed.
- **Fix:** Uninstall any existing version of "ISP Billing" from your device first, then install the new APK.

### Issue 2: "Blocked by Play Protect"
- **Cause:** The APK is signed with a development debug certificate (`debug.keystore`).
- **Fix:** Tap **"More details"** and select **"Install anyway"**.

### Issue 3: Device not detected by ADB
- **Fix:**
  1. Ensure you have proper USB drivers installed (e.g., Google USB Driver or manufacturer driver).
  2. Switch USB connection mode on your phone from "Charging only" to **"File Transfer / MTP"**.
  3. Re-plug the USB cable and verify with `adb devices`.
