#!/usr/bin/env python3
"""
ISP Billing & FreeRADIUS — AI Endpoint, Gemini API & Environment Verifier
Checks:
  1. Required environment variables for Gemini, Google Cloud, Citus, Redis, RADIUS, MFS
  2. Google AI Studio / Gemini REST API endpoint reachability, auth, and latency
  3. Local or Cloud Run BSS /api/v1/health and /api/v1/ai/diagnose endpoints
"""

import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

REQUIRED_ENV_VARS = [
    "GEMINI_API_KEY",
    "GEMINI_MODEL",
    "GOOGLE_CLOUD_PROJECT",
    "DB_HOST",
    "DB_DATABASE",
    "DB_USERNAME",
    "DB_PASSWORD",
    "REDIS_HOST",
    "REDIS_POLICY",
    "RADIUS_SECRET",
    "RADIUS_COA_PORT",
    "BKASH_APP_KEY",
    "BKASH_APP_SECRET",
    "NAGAD_MERCHANT_ID",
]


def load_dotenv_if_present(env_path: str = ".env") -> None:
    if not os.path.exists(env_path):
        return
    with open(env_path, "r", encoding="utf-8") as f:
        for raw in f:
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, val = line.split("=", 1)
            key = key.strip()
            val = val.strip().strip('"').strip("'")
            if key and key not in os.environ:
                os.environ[key] = val


def check_env() -> bool:
    print("\n[1/3] Checking Required Environment Variables...")
    missing = []
    warnings = []
    for var in REQUIRED_ENV_VARS:
        val = os.environ.get(var, "").strip()
        if not val or val.startswith("REPLACE_WITH_"):
            missing.append(var)
        elif var == "REDIS_POLICY" and val != "noeviction":
            warnings.append(f"REDIS_POLICY is '{val}' — MUST be 'noeviction' for MFS SETNX safety.")

    if missing:
        print(f"  [FAIL] Missing or placeholder env vars ({len(missing)}): {', '.join(missing)}")
    else:
        print("  [PASS] All 14 core environment variables are set.")

    for w in warnings:
        print(f"  [WARN] {w}")

    return len(missing) == 0


def check_gemini_endpoint() -> bool:
    print("\n[2/3] Checking Google AI Studio / Gemini API Endpoint...")
    api_key = os.environ.get("GEMINI_API_KEY", "").strip()
    model = os.environ.get("GEMINI_MODEL", "gemini-2.5-flash").strip()

    if not api_key or api_key.startswith("REPLACE_WITH_"):
        print("  [FAIL] GEMINI_API_KEY is not set.")
        return False

    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={api_key}"
    payload = {
        "contents": [{"parts": [{"text": "Reply with exact word: OK"}]}],
        "generationConfig": {"maxOutputTokens": 8, "temperature": 0},
    }
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    t0 = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            elapsed_ms = (time.perf_counter() - t0) * 1000
            body = json.loads(resp.read().decode("utf-8"))
            text = (
                body.get("candidates", [{}])[0]
                .get("content", {})
                .get("parts", [{}])[0]
                .get("text", "")
                .strip()
            )
            print(f"  [PASS] Gemini API ({model}) responded in {elapsed_ms:.1f} ms -> '{text}'")
            return True
    except urllib.error.HTTPError as e:
        err_body = e.read().decode("utf-8", errors="replace")
        print(f"  [FAIL] HTTP {e.code} from Gemini API: {err_body[:240]}")
        return False
    except Exception as e:
        print(f"  [FAIL] Network/TLS error reaching Gemini API: {e}")
        return False


def check_bss_endpoints(base_url: str) -> bool:
    print(f"\n[3/3] Checking BSS & AI Copilot Endpoints at {base_url}...")
    base = base_url.rstrip("/")
    ok = True

    for path in ["/api/v1/health", "/api/v1/ai/diagnose"]:
        url = f"{base}{path}"
        method = "POST" if path.endswith("diagnose") else "GET"
        data = json.dumps({"username": "tapos_test", "symptom": "radtest_check"}).encode("utf-8") if method == "POST" else None
        headers = {"Content-Type": "application/json"} if method == "POST" else {}
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        t0 = time.perf_counter()
        req_timeout = 10 if path == "/api/v1/health" else 30
        try:
            with urllib.request.urlopen(req, timeout=req_timeout) as resp:
                ms = (time.perf_counter() - t0) * 1000
                print(f"  [PASS] {method} {path} -> HTTP {resp.status} ({ms:.1f} ms)")
        except urllib.error.HTTPError as e:
            print(f"  [WARN] {method} {path} -> HTTP {e.code}")
            ok = False
        except Exception as e:
            print(f"  [FAIL] {method} {path} -> {e}")
            ok = False
    return ok


def main() -> int:
    parser = argparse.ArgumentParser(description="Verify ISP Billing AI & Environment setup")
    parser.add_argument("--env-file", default=".env", help="Path to .env file")
    parser.add_argument("--check-env", action="store_true", help="Validate required env vars")
    parser.add_argument("--check-gemini", action="store_true", help="Test Gemini API endpoint")
    parser.add_argument("--check-bss", default="", help="Base URL of BSS API (e.g. http://localhost:8000)")
    args = parser.parse_args()

    load_dotenv_if_present(args.env_file)
    if not (args.check_env or args.check_gemini or args.check_bss):
        args.check_env = True

    results = []
    if args.check_env:
        results.append(check_env())
    if args.check_gemini:
        results.append(check_gemini_endpoint())
    if args.check_bss:
        results.append(check_bss_endpoints(args.check_bss))

    return 0 if all(results) else 1


if __name__ == "__main__":
    sys.exit(main())
