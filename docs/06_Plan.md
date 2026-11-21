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
- [x] **Stage 11**: Cloud Staging (Google Cloud Platform / AWS Free Tier) — **VERIFIED / READY**
  - Note: Automated 7-gate cloud staging suite (`scripts/Run-Stage11CloudStaging.ps1`, `scripts/run_stage11_cloud_staging.sh`, `scripts/test_stage11_cloud_staging.py`), `infra/cloudbuild.yaml`, and `infra/aws/` verified 100%. Safe pre-flight isolation active.
- [x] **Stage 12**: On-Prem High Availability, Observability & Cutover Preparation — **VERIFIED / READY**
- [x] **Stage 13**: Evolution & Day-2 Carrier Operations (Release 2.0 Roadmap, Tech Debt, Postmortem Engine) — **VERIFIED / READY**

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
- [x] **Phase 9 / Stage 9: QA, Benchmarking & Testing Lab Verification** — Clustered Citus & FreeRADIUS lab runner (`scripts/Run-IspLocalQa.ps1`, `scripts/run_isp_local_qa.sh`), 100% test pass across 5 QA sections & 9 CI gates (`stage_9_qa_benchmarking_and_test_lab_report.md`)
- [x] **Phase 10 / Stage 10: On-Prem HA Architecture & Enterprise Storage** — Citus distributed cluster, pgBackRest live WAL archive & differential delta restore, Keepalived VIP 10.10.0.10, Redis Sentinel (`scripts/Run-Stage10HaStorage.ps1`, `scripts/run_stage10_ha_and_storage.sh`, `stage_10_ha_architecture_and_storage_report.md`)
- [x] **Phase 11 / Stage 11: Cloud Staging & Multi-Cloud Readiness** — GCP Cloud Run + Secret Manager, AWS Free Tier EC2 + FreeRADIUS 3.2, zero-cost pre-flight billing guard, AI diagnostic endpoints (`scripts/Run-Stage11CloudStaging.ps1`, `scripts/run_stage11_cloud_staging.sh`, `stage_11_cloud_staging_and_readiness_report.md`)
- [x] **Phase 12 / Stage 12: Zero-Downtime Production Cutover & Rollback Verification** — 8-gate cutover & pilot engine (`scripts/Run-Stage12Cutover.ps1`, `scripts/run_stage12_cutover.sh`, `scripts/test_stage12_cutover_and_pilot.py`), RouterOS v7 pilot directives, 5-minute rollback assurance (`stage_12_ha_observability_and_cutover_report.md`)
- [x] **Phase 13 / Stage 13: Evolution & Day-2 Carrier Operations** — Post-cutover feedback triage, tech debt register (`docs/11-evolution/TECHNICAL_DEBT.md`), blameless postmortem engine (`docs/11-evolution/INCIDENT_POSTMORTEM_RUNBOOK.md`), and Release 2.0 roadmap (`scripts/Run-Stage13Evolution.ps1`, `scripts/run_stage13_evolution.sh`, `scripts/test_stage13_evolution_and_day2_ops.py`, `stage_13_evolution_and_day2_ops_report.md`)

---

## Release 2.0 Carrier Expansion Trackers

- [x] **Release 2.0 / Epic 1: Wi-Fi Hotspot Voucher Management System** — Citus 128-shard tables (`hotspot_vouchers`, `hotspot_profiles`), batch voucher generator with 80mm ESC/POS thermal layout, Redis fast-path verification (<0.4ms), and WISPr rate-limiting VSAs (`scripts/Run-HotspotVoucherVerification.ps1`, `scripts/run_hotspot_voucher_verification.sh`, `scripts/test_release2_hotspot_voucher.py`, `release2_epic1_hotspot_voucher_report.md`)
- [x] **Release 2.0 / Epic 3: ClickHouse 2-Year BTRC IPDR Cluster** — Provisioned `isp_clickhouse` (ClickHouse 24.3), 730-day automated TTL retention (`toIntervalDay(730)`), sub-second forensic search (<35ms), and bulk ingestion micro-batching (`scripts/Run-ClickHouseIpdrVerification.ps1`, `scripts/run_clickhouse_ipdr_verification.sh`, `scripts/test_release2_clickhouse_ipdr.py`, `release2_epic3_clickhouse_ipdr_report.md`)
