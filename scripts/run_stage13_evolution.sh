#!/usr/bin/env bash
# scripts/run_stage13_evolution.sh
# ==============================================================================
# Stage 13: Evolution & Day-2 Carrier Operations Runner (Bash / Linux / WSL)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

echo "========================================================"
echo "  ISP BILLING: STAGE 13 EVOLUTION & DAY-2 OPERATIONS   "
echo "========================================================"
echo "Repository Root: $REPO_ROOT"

PYTHON_BIN="python3"
if ! command -v python3 &>/dev/null; then
    PYTHON_BIN="python"
fi

echo "[+] Executing Stage 13 verification suite..."
"$PYTHON_BIN" "$SCRIPT_DIR/test_stage13_evolution_and_day2_ops.py"

echo "[SUCCESS] Stage 13 Evolution & Day-2 Operations 100% Verified!"
