# Implementation Plan: Carrier Billing System

- [x] 1. Core Documentation Setup (PRD, TRD, Schema)
- [x] 2. Initialize Sharding Engine (Citus 128-Shard Logic)
- [x] 3. Module: NOC Command Center (Telemetry & Storm Simulator)
- [x] 4. Module: Sharded Subscribers Directory (Citus/CRC32)
- [x] 5. Module: Multi-Vendor Tariff Architect (VSA Compiler)
- [x] 6. Module: BNG Fleet Manager (RFC 3576 CoA Tester)
- [x] 7. Module: GenieACS TR-069 Manager (ONT Integration)
- [x] 8. Module: MFS Webhook Lab (HMAC/Idempotency)
- [x] 9. Module: BTRC CGNAT Inspector (RFC 6888 Calculator)
- [x] 10. Module: Hardware Deployment Exporter (Bare-metal Configs)
- [x] 11. Final Polish & Performance Tuning

---

## Stage Delivery & Deployment Trackers

- [x] **Stage 1–5**: Foundation, BSS, CoA UDP 3799, MFS Settlement, Self-Care PWA — **VERIFIED**
- [x] **Stage 6–10**: Security, WBS, Vibe Loops, Test Lab, On-Prem HA Architecture — **VERIFIED**
- [ ] **Stage 11**: Cloud Staging (Google Cloud Platform / AWS Free Tier) — **DEFERRED (Using Local Docker + On-Prem Path)**
  - Note: `infra/cloudbuild.yaml`, `setup_gcp_secret_manager.ps1`, `deploy_stage11_gcp.ps1`, and `infra/aws/` scripts are kept intact for future cloud adoption.
- [x] **Stage 12**: On-Prem High Availability, Observability & Cutover Preparation — **VERIFIED / READY**

---

## Senior Platform Engineer Phased Conversion Tracker

- [x] **Phase 0: Audit & Secrets Inventory** — Comprehensive code-vs-simulation audit, 17-item risk catalog (`phase_0_audit_report.md`)
- [x] **Phase 1: Secrets & Security Hardening** — Docker secrets, HMAC-SHA256, 300s window & SETNX nonce replay protection (`80f8baf`)
- [x] **Phase 2: High Availability & Clustering** — Citus PostgreSQL sharding, Redis Sentinel, dual FreeRADIUS, encrypted S3 backups (`60a2c20`)
- [x] **Phase 3: Billing Correctness & Compliance** — Double-entry general ledger, append-only trigger audit, NBR 5% Mushak-6.3, BTRC 730d reports (`e3263c7`)
- [x] **Phase 4: Observability & SLA Tracking** — Prometheus SLA metrics, alert rules (<10ms AAA, <500ms CoA, <3s unblock), Grafana NOC (`29284ab`)
- [x] **Phase 5: CI/CD & Testing Automation** — GitHub Actions CI/CD workflow, Laravel Pint, PHPStan L8, Trivy & Gitleaks (`25bdaf3`)
- [x] **Phase 6: Multi-Tenancy & Carrier RBAC** — Reseller data partitioning, Eloquent TenantScope, 5-tier RBAC boundaries, IDOR 403 defenses (`4607d09`)
- [x] **Phase 7: Production Cutover & Operator Handover** — 11-gate master readiness suite, RouterOS v7 pilot runner, production operator runbook (`docs/PRODUCTION_OPERATOR_RUNBOOK.md`)
- [x] **Phase 8: Reconnect Storm & Capacity Benchmark** — Real multi-threaded radclient load storm, Redis fast-path (<0.4ms), bulk CoA fleet kicks (`scripts/test_phase8_concurrency_and_storm.py`)
