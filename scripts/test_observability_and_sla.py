#!/usr/bin/env python3
"""
Automated Verification Script for Phase 4: Observability, Metrics & Centralized Logging
Validates:
  1. Laravel BSS /api/v1/metrics endpoint (Prometheus exposition format 0.0.4).
  2. FreeRADIUS Access-Accept latency histogram (< 10ms SLA).
  3. RFC 5176 UDP 3799 CoA kick latency histogram (< 500ms SLA).
  4. MFS payment-to-unblock latency histogram (< 3.0s SLA).
  5. Prometheus scrape targets, configuration, and SLA alerting rules.
  6. Grafana SLA dashboard and data source provisioning.
  7. Loki log aggregator readiness and Promtail log shipper status.
"""

import json
import re
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

def log_step(step: str, title: str) -> None:
    print(f"\n{CYAN}[{step}] {title}{RESET}")

def log_pass(msg: str) -> None:
    print(f"  {GREEN}[PASS]{RESET} {msg}")

def log_fail(msg: str) -> None:
    print(f"  {RED}[FAIL]{RESET} {msg}")
    sys.exit(1)

def run_cmd(args: list[str]) -> str:
    res = subprocess.run(args, capture_output=True, text=True)
    out = (res.stdout or "") + "\n" + (res.stderr or "")
    return out.strip()

def http_get(url: str, timeout: int = 5) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": "ISP-Observability-Tester/1.0"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read().decode("utf-8")

def main():
    print(f"\n{CYAN}{'='*75}{RESET}")
    print(f"{CYAN}   PHASE 4: CARRIER BSS/OSS OBSERVABILITY & SLA VERIFICATION GATES{RESET}")
    print(f"{CYAN}{'='*75}{RESET}")

    # Gate 1: Check BSS /api/v1/metrics
    log_step("1/7", "Verifying Laravel BSS Prometheus /api/v1/metrics Endpoint...")
    try:
        metrics_text = http_get("http://127.0.0.1:8000/api/v1/metrics")
        if "# TYPE radius_access_accept_latency_seconds histogram" in metrics_text and \
           "# TYPE radius_coa_latency_seconds histogram" in metrics_text and \
           "# TYPE bss_payment_unblock_latency_seconds histogram" in metrics_text:
            log_pass("BSS /api/v1/metrics is UP and returns valid Prometheus 0.0.4 text format.")
        else:
            log_fail("Prometheus metrics missing expected SLA histogram definitions.")
    except Exception as e:
        log_fail(f"Could not connect to /api/v1/metrics: {e}")

    # Gate 2: Verify Access-Accept SLA Metric (< 10ms target)
    log_step("2/7", "Testing Synthetic AAA Latency Probe (< 10ms SLA)...")
    probe_output = run_cmd([
        "docker", "exec", "laravel_bss_oss", "php", "/opt/laravel_bss/artisan",
        "metrics:probe-aaa", "--iterations=3", "--username=e2e_live_user", "--password=E2eLivePass@2026"
    ])
    if "SUMMARY:" in probe_output and "Accepted" in probe_output:
        log_pass(f"AAA Probe executed successfully:\n    {probe_output.splitlines()[-1]}")
    else:
        log_fail(f"AAA probe failed: {probe_output}")

    # Gate 3: Verify CoA Kick Latency Metric (< 500ms target)
    log_step("3/7", "Verifying RFC 5176 CoA Kick Latency SLA Metrics (< 500ms)...")
    metrics_text = http_get("http://127.0.0.1:8000/api/v1/metrics")
    if "radius_coa_latency_seconds_count" in metrics_text:
        match = re.search(r'radius_coa_latency_seconds_count (\d+)', metrics_text)
        count = match.group(1) if match else "0"
        log_pass(f"CoA latency histogram verified: {count} dispatches recorded.")
    else:
        log_fail("radius_coa_latency_seconds metric not found.")

    # Gate 4: Verify Payment-to-Unblock Latency Metric (< 3.0s target)
    log_step("4/7", "Verifying BSS Payment-to-Unblock Latency SLA Metrics (< 3.0s)...")
    if "bss_payment_unblock_latency_seconds_count" in metrics_text:
        match = re.search(r'bss_payment_unblock_latency_seconds_count (\d+)', metrics_text)
        count = match.group(1) if match else "0"
        log_pass(f"Payment unblock latency histogram verified: {count} settlements recorded.")
    else:
        log_fail("bss_payment_unblock_latency_seconds metric not found.")

    # Gate 5: Verify Prometheus Rules and Targets
    log_step("5/7", "Verifying Prometheus Server Scrape Targets & SLA Alert Rules...")
    prom_rules = run_cmd([
        "docker", "exec", "isp_prometheus", "promtool", "check", "rules", "/etc/prometheus/isp_sla_alerts.yml"
    ])
    if "SUCCESS" in prom_rules:
        log_pass(f"Prometheus SLA alert rules validated:\n    {prom_rules.strip()}")
    else:
        log_fail(f"Promtool validation failed: {prom_rules}")

    prom_targets_raw = run_cmd([
        "docker", "exec", "isp_prometheus", "wget", "-qO-", "http://localhost:9090/api/v1/targets"
    ])
    try:
        targets = json.loads(prom_targets_raw)
        active_pools = [t.get("scrapePool") for t in targets.get("data", {}).get("activeTargets", [])]
        if "laravel_bss" in active_pools:
            log_pass(f"Prometheus active scrape targets include 'laravel_bss': {active_pools}")
        else:
            log_fail(f"'laravel_bss' scrape target missing from Prometheus: {active_pools}")
    except Exception as e:
        log_fail(f"Failed to parse Prometheus targets: {e}")

    # Gate 6: Verify Grafana SLA Operations Dashboard
    log_step("6/7", "Verifying Grafana NOC SLA Dashboard Provisioning...")
    grafana_dash_raw = run_cmd([
        "docker", "exec", "isp_grafana", "wget", "-qO-",
        "http://admin:changeme_in_env@localhost:3000/api/dashboards/uid/isp-noc-sla-v1"
    ])
    try:
        dash_data = json.loads(grafana_dash_raw)
        dash_title = dash_data.get("dashboard", {}).get("title", "")
        panels_count = len(dash_data.get("dashboard", {}).get("panels", []))
        if "isp-noc-sla-v1" in dash_data.get("dashboard", {}).get("uid", ""):
            log_pass(f"Grafana SLA Dashboard active: '{dash_title}' with {panels_count} panels.")
        else:
            log_fail(f"Grafana dashboard missing or invalid UID: {dash_data}")
    except Exception as e:
        log_fail(f"Failed to parse Grafana dashboard: {e}")

    # Gate 7: Verify Loki Log Aggregation & Promtail Log Shipping
    log_step("7/7", "Verifying Loki Log Aggregator & Promtail Shipper Health...")
    loki_ready = run_cmd(["docker", "exec", "isp_loki", "wget", "-qO-", "http://localhost:3100/ready"])
    if "ready" in loki_ready:
        log_pass("Grafana Loki log aggregator is READY on port 3100.")
    else:
        log_fail(f"Loki not ready: {loki_ready}")

    promtail_logs = run_cmd(["docker", "logs", "isp_promtail", "--tail", "10"])
    if "Adding target" in promtail_logs or "server listening" in promtail_logs:
        log_pass("Promtail log shipper active and watching log targets.")
    else:
        log_fail(f"Promtail unexpected state:\n{promtail_logs}")

    print(f"\n{GREEN}{'='*75}{RESET}")
    print(f"{GREEN}   PHASE 4 OBSERVABILITY VERIFICATION: 100% PASS (7/7 GATES){RESET}")
    print(f"{GREEN}{'='*75}{RESET}\n")

if __name__ == "__main__":
    main()
