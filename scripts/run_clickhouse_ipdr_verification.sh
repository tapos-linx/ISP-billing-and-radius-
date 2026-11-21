#!/usr/bin/env bash
# scripts/run_clickhouse_ipdr_verification.sh
# ==============================================================================
# Release 2.0 (Epic 3): ClickHouse IPDR Runner (Bash / Linux / WSL)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$SCRIPT_DIR")"

echo "========================================================"
echo "  RELEASE 2.0 (EPIC 3): CLICKHOUSE BTRC IPDR SUITE     "
echo "========================================================"
echo "Repository Root: $REPO_ROOT"

PYTHON_BIN="python3"
if ! command -v python3 &>/dev/null; then
    PYTHON_BIN="python"
fi

echo "[+] Executing Release 2.0 Epic 3 verification suite..."
"$PYTHON_BIN" "$SCRIPT_DIR/test_release2_clickhouse_ipdr.py"

echo "[SUCCESS] Release 2.0 Epic 3 ClickHouse IPDR Engine 100% Verified!"
