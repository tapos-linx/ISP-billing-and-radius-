#!/usr/bin/env python3
"""
Automated Verification Script for Phase 6: Multi-tenancy, RBAC & Reseller Data Isolation
Validates:
  1. SuperAdmin unrestricted visibility across all tenants.
  2. Reseller subscriber query isolation (only assigned tenant).
  3. Reseller IP pool isolation (only assigned subnets).
  4. Cross-tenant subscriber direct access leak prevention (403/404).
  5. Cross-tenant invoice direct access leak prevention (403/404).
  6. Horizontal privilege escalation prevention (X-Tenant-ID tampering rejected).
  7. RBAC role-to-permission boundaries (Reseller and BillingClerk restrictions).
"""

import json
import os
import secrets
import subprocess
import sys
import time
import urllib.request
import urllib.error

GREEN = "\033[92m"
RED = "\033[91m"
CYAN = "\033[96m"
YELLOW = "\033[93m"
RESET = "\033[0m"

BASE_URL = os.environ.get("BSS_BASE_URL", "http://127.0.0.1:8000/api/v1")

TOKEN_SUPERADMIN = "token_superadmin_carrier_2026"
TOKEN_RESELLER_UTTARA = "token_reseller_uttara_2026"
TOKEN_RESELLER_DHANMONDI = "token_reseller_dhanmondi_2026"
TOKEN_BILLING_CLERK = "token_billing_carrier_2026"

def log_step(step: str, title: str) -> None:
    print(f"\n{CYAN}[{step}] {title}{RESET}")

def log_pass(msg: str) -> None:
    print(f"  {GREEN}[PASS]{RESET} {msg}")

def log_fail(msg: str) -> None:
    print(f"  {RED}[FAIL]{RESET} {msg}")
    sys.exit(1)

def api_request(method: str, path: str, token: str, data: dict = None, headers: dict = None) -> tuple[int, dict]:
    url = f"{BASE_URL}{path}"
    req_headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "Content-Type": "application/json",
        "User-Agent": "Carrier-MultiTenancy-Tester/1.0"
    }
    if headers:
        req_headers.update(headers)

    body = json.dumps(data).encode("utf-8") if data else None
    req = urllib.request.Request(url, data=body, headers=req_headers, method=method)

    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            content = resp.read().decode("utf-8")
            return resp.status, json.loads(content) if content else {}
    except urllib.error.HTTPError as e:
        content = e.read().decode("utf-8")
        try:
            return e.code, json.loads(content)
        except Exception:
            return e.code, {"raw": content}

def main():
    print(f"\n{CYAN}{'='*75}{RESET}")
    print(f"{CYAN}   PHASE 6: MULTI-TENANCY, RESELLER ISOLATION & RBAC SECURITY GATES{RESET}")
    print(f"{CYAN}{'='*75}{RESET}")

    # Gate 1: SuperAdmin Visibility
    log_step("1/7", "Testing SuperAdmin Multi-Tenant Visibility...")
    status, res = api_request("GET", "/tenants", TOKEN_SUPERADMIN)
    if status == 200 and len(res.get("tenants", [])) >= 3:
        log_pass(f"SuperAdmin retrieved {len(res['tenants'])} tenants: {[t['slug'] for t in res['tenants']]}")
    else:
        log_fail(f"SuperAdmin /tenants query failed (status {status}): {res}")

    # Gate 2: Reseller Tenant Scoping (Reseller Uttara)
    log_step("2/7", "Verifying Reseller Subscriber Query Scoping (Tenant 2)...")
    status, res = api_request("GET", "/subscribers", TOKEN_RESELLER_UTTARA)
    if status == 200:
        subs = res.get("data", [])
        if not subs:
            log_fail("No subscribers returned for Reseller Uttara.")
        for s in subs:
            if s.get("tenant_id") != 2:
                log_fail(f"CROSS-TENANT DATA LEAK: Subscriber {s['subscriber_id']} has tenant_id {s.get('tenant_id')} (expected 2)")
        log_pass(f"All {len(subs)} subscribers returned belong strictly to Tenant 2 (Uttara).")
    else:
        log_fail(f"Reseller subscribers list failed (status {status}): {res}")

    # Gate 3: Reseller IP Pool Isolation
    log_step("3/7", "Verifying Reseller Allocated IP Pool Isolation...")
    status, res = api_request("GET", "/ip-pools", TOKEN_RESELLER_UTTARA)
    if status == 200:
        pools = res.get("pools", [])
        if not pools:
            log_fail("No IP pools returned for Reseller Uttara.")
        for p in pools:
            if p.get("tenant_id") != 2:
                log_fail(f"CROSS-TENANT IP POOL LEAK: Pool {p['pool_name']} has tenant_id {p.get('tenant_id')}")
        log_pass(f"Reseller isolated to assigned pools: {[p['pool_name'] for p in pools]} (Subnet: {pools[0]['subnet_cidr']})")
    else:
        log_fail(f"Reseller ip-pools query failed (status {status}): {res}")

    # Gate 4: Cross-Tenant Subscriber Direct Access Leak Prevention
    log_step("4/7", "Testing Cross-Tenant Subscriber Direct Access Prevention...")
    dhanmondi_sub_id = "33333333-3333-3333-3333-333333333333"
    status, res = api_request("GET", f"/subscribers/{dhanmondi_sub_id}", TOKEN_RESELLER_UTTARA)
    if status in (403, 404):
        log_pass(f"Cross-tenant access correctly blocked with HTTP {status}: {res.get('message', res.get('error'))}")
    else:
        log_fail(f"SECURITY BREACH: Reseller Uttara was able to view Dhanmondi subscriber! Status: {status}, Response: {res}")

    # Gate 5: Cross-Tenant Invoice Direct Access Leak Prevention
    log_step("5/7", "Testing Cross-Tenant Invoice Direct Access Prevention...")
    dhanmondi_inv_id = "33333333-fa00-3333-3333-333333333333"
    status, res = api_request("GET", f"/invoices/{dhanmondi_inv_id}", TOKEN_RESELLER_UTTARA)
    if status in (403, 404):
        log_pass(f"Cross-tenant invoice access correctly blocked with HTTP {status}: {res.get('message', res.get('error'))}")
    else:
        log_fail(f"SECURITY BREACH: Reseller Uttara was able to view Dhanmondi invoice! Status: {status}, Response: {res}")

    # Gate 6: Horizontal Privilege Escalation Prevention (X-Tenant-ID Header Spoofing)
    log_step("6/7", "Testing Horizontal Privilege Escalation (X-Tenant-ID Spoofing)...")
    status, res = api_request("GET", "/subscribers", TOKEN_RESELLER_UTTARA, headers={"X-Tenant-ID": "3"})
    if status == 403:
        log_pass(f"Tenant impersonation blocked with HTTP 403: {res.get('message', res.get('error'))}")
    else:
        log_fail(f"SECURITY BREACH: Reseller was able to spoof X-Tenant-ID! Status: {status}")

    # Gate 7: RBAC Role & Permission Boundary Enforcement
    log_step("7/7", "Verifying RBAC Role-to-Permission Boundaries...")
    # Reseller cannot view global tenants
    status1, _ = api_request("GET", "/tenants", TOKEN_RESELLER_UTTARA)
    if status1 == 403:
        log_pass("RBAC Guard: Reseller blocked from /tenants management (HTTP 403).")
    else:
        log_fail(f"RBAC FAILURE: Reseller accessed /tenants with status {status1}")

    # BillingClerk cannot create subscribers
    status2, _ = api_request("POST", "/subscribers", TOKEN_BILLING_CLERK, data={"username": "test_clerk_sub", "password": "SecretPassword123!"})
    if status2 == 403:
        log_pass("RBAC Guard: BillingClerk blocked from subscriber provisioning (HTTP 403).")
    else:
        log_fail(f"RBAC FAILURE: BillingClerk created subscriber with status {status2}")

    print(f"\n{GREEN}{'='*75}{RESET}")
    print(f"{GREEN}   PHASE 6 MULTI-TENANCY & RBAC VERIFICATION: 100% PASS (7/7 GATES){RESET}")
    print(f"{GREEN}{'='*75}{RESET}\n")

if __name__ == "__main__":
    main()
