#!/usr/bin/env bash
# scripts/run_hotspot_voucher_verification.sh
# ==============================================================================
# Release 2.0 (Epic 1): Wi-Fi Hotspot Voucher Runner (Bash / Linux / WSL)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

echo "========================================================"
echo "  RELEASE 2.0 (EPIC 1): WI-FI HOTSPOT VOUCHER SUITE    "
echo "========================================================"
echo "Repository Root: $REPO_ROOT"

PYTHON_BIN="python3"
if ! command -v python3 &>/dev/null; then
    PYTHON_BIN="python"
fi

echo "[+] Executing Release 2.0 Epic 1 verification suite..."
"$PYTHON_BIN" "$SCRIPT_DIR/test_release2_hotspot_voucher.py"

echo "[SUCCESS] Release 2.0 Epic 1 Hotspot Voucher Engine 100% Verified!"
