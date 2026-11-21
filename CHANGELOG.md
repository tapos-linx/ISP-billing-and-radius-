# Changelog

All notable changes to the **ISP Billing & FreeRADIUS Automation** platform will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.1.0-dev] - 2026-11-21 (Stage 13: Evolution & Release 2.0 Inception)

### Added
- **Release 2.0 Epic 1 (Wi-Fi Hotspot Voucher Engine):** Implemented Citus 128-shard tables (`hotspot_vouchers`, `hotspot_profiles`), batch voucher generator with 80mm ESC/POS layout & bKash QR payloads, Redis fast-path verification (<0.4ms), captive portal authorization with WISPr VSAs (`WISPr-Bandwidth-Max-Down` / `Up`), interim accounting quota consumption, and 6 PHPUnit feature tests (42/42 total tests pass).
- **Release 2.0 Roadmap:** Created `docs/11-evolution/PRODUCT_ROADMAP.md` covering Wi-Fi Hotspot vouchers, VoIP/SIP CDR call rating, ClickHouse 2-year BTRC IPDR storage, and GenieACS TR-069 optical automation.
- **Technical Debt Register:** Established `docs/11-evolution/TECHNICAL_DEBT.md` with cataloged debt items (`TD-001` through `TD-006`), severity matrix, complexity estimates, and architectural remediation targets.
- **Incident Postmortem Runbook:** Added `docs/11-evolution/INCIDENT_POSTMORTEM_RUNBOOK.md` defining blameless culture, 5-Whys methodology, severity tiers (P1–P4), and MTTD/MTTR targets.
- **Incident Postmortem Template:** Created standardized markdown artifact `docs/11-evolution/POSTMORTEM_TEMPLATE.md` with executive summary, impact quantification, timeline, 5-whys, and CAPA tracking.
- **Feedback Triage Process:** Implemented `docs/11-evolution/FEEDBACK_TRIAGE_PROCESS.md` detailing multi-channel ingestion, automated triage categorization, and bi-weekly evolution review council.
- **Automated Verification Suite:** Built `scripts/test_stage13_evolution_and_day2_ops.py`, `scripts/Run-Stage13Evolution.ps1`, and `scripts/run_stage13_evolution.sh`.

---

## [1.0.0] - 2026-11-21 (Production-Ready Carrier Milestone: Stages 0–12)

### Added
- **Stage 12 (Zero-Downtime Production Cutover):** 8-gate cutover simulation, RouterOS v7 pilot directives, single-subscriber canary (`tapos_pilot`), 5-minute rollback safety drill, and production operator runbook (`STAGE_12_CUTOVER_RUNBOOK.md`).
- **Stage 11 (Cloud Staging & Multi-Cloud Readiness):** GCP Cloud Run deployment configuration, GCE e2-micro FreeRADIUS VM bootstrap, AWS Free Tier EC2 setup, Secret Manager synchronization, and zero-cost billing isolation guard.
- **Stage 10 (On-Prem HA Architecture & Storage):** Distributed Citus PostgreSQL coordinator + 2 workers, live WAL archiving with pgBackRest, differential delta restore verification, Keepalived VRRP VIP (`10.10.0.10`), and Redis Sentinel with `noeviction`.
- **Stage 9 (Local QA Lab & Testing Automation):** Multi-service carrier QA runner (`Run-IspLocalQa.ps1`), 5-section testing matrix, and automated environment provisioning.
- **Stage 8 / Phase 8 (Concurrency & Reconnect Storm):** Real multi-threaded `radclient` load storm simulation, fast-path (<0.4ms) Redis hit validation, and fleet-wide RFC 3576 CoA burst kicks.
- **Phase 7 (Production Cutover & Operator Handover):** 11-gate master readiness suite and production operator runbook (`PRODUCTION_OPERATOR_RUNBOOK.md`).
- **Phase 6 (Multi-Tenancy & Carrier RBAC):** Reseller data partitioning, Eloquent `TenantScope`, 5-tier RBAC boundaries, and IDOR protection.
- **Phase 5 (CI/CD Automation):** GitHub Actions CI/CD workflows, Laravel Pint formatting, PHPStan Level 8 static analysis, Trivy vulnerability scanner, and Gitleaks secret detection.
- **Phase 4 (Observability & SLA Monitoring):** Prometheus exporter metrics (`radius_auth_latency_ms`, `coa_duration_ms`), Alertmanager notification rules, and Grafana carrier NOC dashboard.
- **Phase 3 (Billing Correctness & Compliance):** Immutable double-entry general ledger, append-only trigger audit logs, NBR 5% Mushak-6.3 tax calculation, and BTRC 730-day reporting compliance.
- **Phase 2 (High Availability):** Citus PostgreSQL 128-shard architecture, Redis Sentinel high availability, dual FreeRADIUS instances, and encrypted MinIO S3 backup pipeline.
- **Phase 1 (Security Hardening):** Docker secret isolation, HMAC-SHA256 webhooks, and 300s replay-protection nonce window with Redis `SETNX`.
- **Stage 0 (Project Intake & Baseline):** Project brief, functional & non-functional requirements, and engineering baseline rules (`BASELINE_AND_RULES.md`).
