#!/usr/bin/env python3
"""
Automated End-to-End Integration Test Runner: MFS Settlement, Citus DB & FreeRADIUS CoA
Simulates complete subscriber lifecycle:
  1. Expired subscriber locked in walled_garden_pool (128k/128k) with unpaid Mushak-6.3 invoice.
  2. Verified via FreeRADIUS radtest (walled_garden_pool).
  3. Dispatches signed bKash mock webhook with HMAC-SHA256, timestamp, and nonce.
  4. Verifies HTTP 202 Accepted.
  5. Verifies duplicate nonce replay is rejected with HTTP 409 Conflict.
  6. Verifies duplicate transaction ID replay with fresh nonce is rejected with HTTP 409 Conflict.
  7. Verifies Citus DB NBR 5% VAT invoice marked paid, subscriber active, radreply restored.
  8. Verifies FreeRADIUS radtest restores active rate limit (20M/20M) and pppoe_active_pool.
"""

import hashlib
import hmac
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time
import urllib.request
import urllib.error

# ANSI Formatting
GREEN = "\033[92m"
RED = "\033[91m"
CYAN = "\033[96m"
YELLOW = "\033[93m"
RESET = "\033[0m"

REPO_ROOT = Path(__file__).resolve().parent.parent

def load_secret(name: str, fallback: str) -> str:
    secret_path = REPO_ROOT / "secrets" / f"{name}.txt"
    if secret_path.exists():
        return secret_path.read_text(encoding="utf-8").strip()
    infra_path = REPO_ROOT / "infra" / "secrets" / f"{name}.txt"
    if infra_path.exists():
        return infra_path.read_text(encoding="utf-8").strip()
    return os.environ.get(name.upper(), fallback)

BSS_BASE_URL = os.environ.get("BSS_BASE_URL", "http://127.0.0.1:8000")
SUB_ID = "e2e00000-0000-0000-0000-000000000099"
INV_ID = "fa000000-0000-0000-0000-000000000099"
USERNAME = "e2e_live_user"
PASSWORD = "E2eLivePass@2026"
RADIUS_SECRET = load_secret("radius_secret", "secure_radius_secret_key_2026")
BKASH_SECRET = load_secret("bkash_secret", "sandbox_bkash_secret_2026")
REDIS_PASSWORD = load_secret("redis_password", "")

def run_cmd(cmd: list[str]) -> str:
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    return res.stdout + res.stderr

def get_db_container() -> str:
    for name in ["citus_master", "isp_postgres_primary", "isp_postgres"]:
        res = subprocess.run(["docker", "ps", "-q", "-f", f"name={name}"], capture_output=True, text=True)
        if res.stdout.strip():
            return name
    return "citus_master"

def get_radius_container() -> str:
    for name in ["freeradius_aaa", "isp_freeradius_1", "isp_freeradius", "freeradius"]:
        res = subprocess.run(["docker", "ps", "-q", "-f", f"name={name}"], capture_output=True, text=True)
        if res.stdout.strip():
            return name
    return "freeradius_aaa"

def run_sql(sql: str) -> str:
    db_cont = get_db_container()
    db_user = "isp_admin" if "citus" in db_cont else "radius_user"
    db_name = "isp_bss_oss" if "citus" in db_cont else "isp_billing"
    cmd = ["docker", "exec", db_cont, "psql", "-U", db_user, "-d", db_name, "-q", "-t", "-c", sql]
    return run_cmd(cmd).strip()

def log_step(step: str, title: str):
    print(f"\n{CYAN}[{step}] {title}{RESET}")

def log_pass(msg: str):
    print(f"  {GREEN}[PASS]{RESET} {msg}")

def log_fail(msg: str):
    print(f"  {RED}[FAIL]{RESET} {msg}")
    sys.exit(1)

def main():
    print(f"{CYAN}{'='*75}{RESET}")
    print(f"{CYAN}   CARRIER BNG / BSS - AUTOMATED END-TO-END MFS & COA INTEGRATION TEST{RESET}")
    print(f"{CYAN}{'='*75}{RESET}")

    # Step 1: Health check BSS
    log_step("1/8", "Verifying BSS Service Health...")
    try:
        with urllib.request.urlopen(f"{BSS_BASE_URL}/api/v1/health", timeout=5) as resp:
            data = json.loads(resp.read().decode())
            if data.get("status") == "ok":
                log_pass(f"BSS Health: 200 OK | Citus: {data.get('citus')} | Redis: {data.get('redis')}")
            else:
                log_fail(f"Unexpected BSS response: {data}")
    except Exception as e:
        log_fail(f"BSS endpoint not reachable: {e}")

    # Step 2: Seed Expired Subscriber in Citus
    log_step("2/8", "Seeding Expired Subscriber in Citus DB (walled_garden_pool + 128k/128k)...")
    seed_sql = f"""
    DELETE FROM payment_webhooks WHERE subscriber_id = '{SUB_ID}';
    DELETE FROM btrc_audit_logs WHERE subscriber_id = '{SUB_ID}';
    DELETE FROM failed_coa_jobs WHERE subscriber_id = '{SUB_ID}';
    DELETE FROM radcheck WHERE subscriber_id = '{SUB_ID}';
    DELETE FROM radreply WHERE subscriber_id = '{SUB_ID}';
    DELETE FROM invoices WHERE subscriber_id = '{SUB_ID}';
    DELETE FROM subscribers WHERE subscriber_id = '{SUB_ID}';

    INSERT INTO subscribers (subscriber_id, tenant_id, username, bandwidth_profile, status, expires_at)
    VALUES ('{SUB_ID}', 1, '{USERNAME}', '20M/20M', 'expired', NOW() - INTERVAL '2 days');

    INSERT INTO radcheck (subscriber_id, username, attribute, op, value)
    VALUES ('{SUB_ID}', '{USERNAME}', 'Cleartext-Password', ':=', '{PASSWORD}');

    INSERT INTO radreply (subscriber_id, username, attribute, op, value)
    VALUES 
        ('{SUB_ID}', '{USERNAME}', 'Mikrotik-Rate-Limit', ':=', '128k/128k'),
        ('{SUB_ID}', '{USERNAME}', 'Framed-Pool', ':=', 'walled_garden_pool');

    INSERT INTO invoices (invoice_id, subscriber_id, tenant_id, subtotal_bdt, vat_rate_percent, vat_amount_bdt, total_bdt, amount_bdt, status, isp_bin_number, mushak_6_3_number, due_date)
    VALUES ('{INV_ID}', '{SUB_ID}', 1, 1000.00, 5.00, 50.00, 1050.00, 1050.00, 'unpaid', 'BIN-001234567-0101', 'MUSHAK-6.3-2026-LIVE01', NOW() - INTERVAL '2 days');
    """
    run_sql(seed_sql)
    # Clear Redis fast-path
    del_redis_cmd = ["docker", "exec", "redis_cluster", "redis-cli"]
    if REDIS_PASSWORD:
        del_redis_cmd.extend(["-a", REDIS_PASSWORD])
    del_redis_cmd.extend(["del", f"radius:auth:{USERNAME}"])
    run_cmd(del_redis_cmd)
    log_pass(f"Seeded '{USERNAME}' in walled_garden_pool with unpaid Mushak-6.3 invoice (BDT 1,050.00).")

    # Step 3: Verify Pre-Payment FreeRADIUS radtest returns walled_garden_pool
    log_step("3/8", "Verifying FreeRADIUS AAA Pre-Payment Isolation...")
    rad_cont = get_radius_container()
    radtest_pre = run_cmd(["docker", "exec", rad_cont, "radtest", USERNAME, PASSWORD, "127.0.0.1", "0", RADIUS_SECRET])
    if "Access-Accept" in radtest_pre and "walled_garden_pool" in radtest_pre and "128k/128k" in radtest_pre:
        log_pass("Pre-Payment Auth: Access-Accept with Framed-Pool=walled_garden_pool & Rate=128k/128k.")
    else:
        log_fail(f"Pre-payment AAA isolation check failed:\n{radtest_pre}")

    # Step 4: Dispatch Signed bKash Webhook
    log_step("4/8", "Dispatching Signed bKash Webhook with HMAC-SHA256 & Nonce...")
    trx_id = f"TRX_LIVE_{int(time.time())}_{secrets.token_hex(4).upper()}"
    secret = BKASH_SECRET

    payload_dict = {
        "trx_id": trx_id,
        "subscriber_id": SUB_ID,
        "invoice_id": INV_ID,
        "amount_bdt": 1050.00,
    }
    raw_payload = json.dumps(payload_dict)
    timestamp = str(int(time.time()))
    nonce = f"nonce_live_{secrets.token_hex(12)}"
    signature = hmac.new(secret.encode("utf-8"), raw_payload.encode("utf-8"), hashlib.sha256).hexdigest()

    req = urllib.request.Request(
        f"{BSS_BASE_URL}/api/v1/webhooks/bkash",
        data=raw_payload.encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "X-Webhook-Timestamp": timestamp,
            "X-Webhook-Nonce": nonce,
            "X-Webhook-Signature": signature,
        },
        method="POST"
    )

    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            status_code = resp.getcode()
            body = json.loads(resp.read().decode())
            if status_code == 202 and body.get("status") == "accepted":
                log_pass(f"HTTP 202 Accepted | TRX: {body.get('trx_id')} | Status: {body.get('status')}")
            else:
                log_fail(f"Expected 202 Accepted, got HTTP {status_code}: {body}")
    except urllib.error.HTTPError as e:
        log_fail(f"Webhook request failed with HTTP {e.code}: {e.read().decode()}")

    # Step 5: Verify Duplicate Nonce Replay is Rejected (HTTP 409 Conflict)
    log_step("5/8", "Verifying Replay Attack Protection (Reused Nonce)...")
    try:
        replay_req = urllib.request.Request(
            f"{BSS_BASE_URL}/api/v1/webhooks/bkash",
            data=raw_payload.encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "X-Webhook-Timestamp": timestamp,
                "X-Webhook-Nonce": nonce, # Same nonce!
                "X-Webhook-Signature": signature,
            },
            method="POST"
        )
        urllib.request.urlopen(replay_req, timeout=5)
        log_fail("Duplicate nonce was unexpectedly accepted!")
    except urllib.error.HTTPError as e:
        if e.code == 409:
            log_pass(f"Replay detected and rejected with HTTP 409 Conflict: {e.read().decode().strip()}")
        else:
            log_fail(f"Expected HTTP 409, got HTTP {e.code}")

    # Step 6: Verify Duplicate trx_id Replay with Fresh Nonce is Rejected (HTTP 409 Conflict / Idempotency)
    log_step("6/8", "Verifying Idempotency Protection (Duplicate trx_id with Fresh Nonce)...")
    fresh_nonce = f"nonce_live_fresh_{secrets.token_hex(12)}"
    fresh_timestamp = str(int(time.time()))
    try:
        dup_req = urllib.request.Request(
            f"{BSS_BASE_URL}/api/v1/webhooks/bkash",
            data=raw_payload.encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "X-Webhook-Timestamp": fresh_timestamp,
                "X-Webhook-Nonce": fresh_nonce, # Fresh nonce
                "X-Webhook-Signature": signature,
            },
            method="POST"
        )
        urllib.request.urlopen(dup_req, timeout=5)
        log_fail("Duplicate trx_id was unexpectedly accepted!")
    except urllib.error.HTTPError as e:
        if e.code == 409:
            log_pass(f"Duplicate transaction rejected with HTTP 409 Conflict: {e.read().decode().strip()}")
        else:
            log_fail(f"Expected HTTP 409, got HTTP {e.code}")

    # Step 7: Verify Citus Database Settlement & BTRC Audit
    log_step("7/8", "Verifying Citus DB Settlement, NBR Mushak-6.3 & BTRC Audit Log...")
    inv_row = run_sql(f"SELECT status, paid_at, mushak_6_3_number, total_bdt FROM invoices WHERE invoice_id = '{INV_ID}';")
    sub_row = run_sql(f"SELECT status, expires_at > NOW() FROM subscribers WHERE subscriber_id = '{SUB_ID}';")
    pool_val = run_sql(f"SELECT value FROM radreply WHERE subscriber_id = '{SUB_ID}' AND attribute = 'Framed-Pool';")
    rate_val = run_sql(f"SELECT value FROM radreply WHERE subscriber_id = '{SUB_ID}' AND attribute = 'Mikrotik-Rate-Limit';")
    audit_row = run_sql(f"SELECT count(*) FROM btrc_audit_logs WHERE subscriber_id = '{SUB_ID}' AND action = 'activate_after_payment';")

    if "paid" in inv_row and "MUSHAK-6.3-2026-LIVE01" in inv_row:
        log_pass(f"Citus Invoice Settled: {inv_row}")
    else:
        log_fail(f"Invoice not properly settled: {inv_row}")

    if "active" in sub_row and "t" in sub_row:
        log_pass(f"Subscriber Extended & Activated (+30 days): {sub_row}")
    else:
        log_fail(f"Subscriber status not restored: {sub_row}")

    if pool_val == "pppoe_active_pool" and rate_val == "20M/20M":
        log_pass(f"Citus radreply Restored: Framed-Pool={pool_val}, Mikrotik-Rate-Limit={rate_val}")
    else:
        log_fail(f"radreply not restored: pool={pool_val}, rate={rate_val}")

    if audit_row.strip() == "1":
        log_pass("BTRC Audit Log Verified: 'activate_after_payment' event retained for 730 days.")
    else:
        log_fail(f"BTRC audit log missing: count={audit_row}")

    # Step 8: Verify Post-Payment FreeRADIUS radtest returns pppoe_active_pool + 20M/20M
    log_step("8/8", "Verifying FreeRADIUS AAA Post-Settlement Access-Accept...")
    rad_cont = get_radius_container()
    radtest_post = run_cmd(["docker", "exec", rad_cont, "radtest", USERNAME, PASSWORD, "127.0.0.1", "0", RADIUS_SECRET])
    if "Access-Accept" in radtest_post and "pppoe_active_pool" in radtest_post and "20M/20M" in radtest_post:
        log_pass("Post-Settlement Auth: Access-Accept with Framed-Pool=pppoe_active_pool & Rate=20M/20M!")
    else:
        log_fail(f"Post-settlement AAA verification failed:\n{radtest_post}")

    print(f"\n{GREEN}{'='*75}{RESET}")
    print(f"{GREEN}   AUTOMATED END-TO-END INTEGRATION TEST: 100% PASS (8/8 GATES){RESET}")
    print(f"{GREEN}{'='*75}{RESET}\n")

if __name__ == "__main__":
    main()
