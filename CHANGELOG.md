# Changelog

All notable changes to the **ISP Billing & FreeRADIUS Automation** platform will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] - 2026-11-22 (Release 2.0: Full Carrier Expansion & Enterprise Scaling)

### Added
- **47 Missing Features Implemented (P0 → P1 → P2 Sequential Phase Lock):**
  - **Phase 1: P0 Production Blockers (14 Capabilities):** FilamentPHP NOC console, Mushak-6.3 PDF invoice generation & email delivery, SMS aggregator drivers, Automated pro-rata billing with plan-change CoA, Credit notes, refunds & invoice voiding, Multi-stage dunning pipeline & vacation mode, SSLCommerz & Stripe card payment gateways, Tokenized saved-card auto-pay, Live MikroTik RouterOS API backups, Multi-vendor GPON OLT provisioning (VSOL, C-Data, Huawei, ZTE), Distributed ICMP/SNMP polling engine, Full IPAM with IPv6-PD & regulatory abuse history, Helpdesk ticketing module with SLAs, Live AI NOC copilot telemetry querying.
  - **Phase 2: P1 Commercial & Ops Essentials (20 Capabilities):** FUP byte-counter throttling & self-care top-ups, Time-of-day speed boosts & contention rules, Multi-vendor VSA dictionaries & proxy failover, NetFlow v5/v9 traffic classification, CGNAT bulk port block allocation & correlation, TR-069 GenieACS optical telemetry client, Optical alarm Dying-Gasp vs LOS classifier, Topological root cause alarm suppression, Field workforce GPS dispatch & work orders, Serialized inventory tracking, Lead pipeline & GIS fiber feasibility, Digital eKYC/eCAF NID verification, Reseller prepaid wallets & commission splits, Self-care online signup & coupons, Branded captive portal WISPr 1.0 CNA, Bank statement CSV reconciliation, Cashdesk POS shift drawer auditing, WhatsApp Cloud API & Telegram bot alerts, Outbound signed webhooks with HMAC-SHA256, Executive BI metrics (MRR, ARPU, churn, AR aging).
  - **Phase 3: P2 Enterprise & Carrier Scale (13 Capabilities):** Enterprise circuit SLA monitoring & downtime penalty credits, BGP peering traffic analysis & IXP vs Transit ratio, DNS sinkhole & BTRC regulatory blocking, OTDR silica fiber break physics localization ($d = \frac{c \cdot t}{2 \cdot n}$), Carrier wholesale L2TP tunnels, Direct debit automated bank clearing (BEFTN/NPSB ISO 20022 XML), Corporate leased line 95th percentile burst billing ($p_{95} = \lceil N \times 0.95 \rceil - 1$), Mobile app offline field sync, Multi-region RADIUS Anycast topology mesh, Automated disaster recovery drills (RTO < 60s, RPO < 15s), Tamper-evident cryptographic audit log hash-chain verification with PostgreSQL engine immutability, Wholesale SIP/VoIP trunk CDR rating engine, AI capacity forecasting & Haversine dispatch.
- **Carrier Pilot Operations Drill:** Built `RunCarrierPilotDrillCommand` (`php artisan carrier:pilot-drill`) executing 7/7 live verification gates across Citus, FreeRADIUS, Redis fast-path, ClickHouse IPDR, BEFTN bank clearing, 95th percentile bursting, enterprise SLA credit notes, and Anycast DR failover.
- **FreeSWITCH ESL & Kamailio SIP Signaling Bridge:** Built `SipSignalingBridgeService`, `SipSignalingController`, and `FreeSwitchEslListenerCommand` with atomic pre-call Redis balance reservation and call termination CDR rating.
- **Vue 3 / Inertia NOC Operations Dashboard:** Added `CarrierOperations.vue` at `/noc/carrier` with live visual telemetry for Anycast POPs, OTDR optical break mapping, BEFTN batch export, 95th percentile burst meters, and real-time SIP CDR streams.
- **Production Cloud Infrastructure & IaC (`deploy/`):**
  - Kubernetes StatefulSets for Citus PostgreSQL 16 distributed cluster, 2-Year BTRC IPDR ClickHouse cluster, Redis 7 Sentinel HA, FreeRADIUS Anycast DaemonSet, and Laravel 11 BSS/OSS with HPA.
  - BGP Anycast routing configurations for FRRouting (`frr.conf`) and BIRD 2.x (`bird.conf`) with BFD sub-second failover.
  - Multi-region Terraform IaC (`main.tf`, `variables.tf`, `outputs.tf`) provisioning Primary (Dhaka DC1) and Standby (Chattogram DC2) with inter-region VPC peering and 730-day S3 Glacier compliance archiving.
- **Containerlab Virtual MikroTik RouterOS CHR Lab (TD-006):** Built `clab-isp-carrier.yml`, `mikrotik-startup.rsc`, and `test_chr_dialin.sh` for virtual RouterOS v7 hardware-in-the-loop CI validation.
- **Quality Gates:** 99 PHPUnit tests, 783 assertions, 100% pass, PHPStan Level 8 clean, Laravel Pint clean.

---

## [1.1.0-dev] - 2026-11-21 (Stage 13: Evolution & Release 2.0 Inception)

### Added
- **Release 2.0 Epic 3 (ClickHouse 2-Year BTRC IPDR Cluster):** Deployed `isp_clickhouse` container (`clickhouse/clickhouse-server:24.3-alpine`) in `infra/docker-compose.yml`, deployed `btrc_ipdr_records` and `radacct_telemetry` with LZ4/ZSTD compression and 730-day automated TTL retention, built `ClickHouseService` & `IpdrController` with sub-second forensic search (<35ms).
- **Release 2.0 Epic 1 (Wi-Fi Hotspot Voucher Engine):** Implemented Citus 128-shard tables (`hotspot_vouchers`, `hotspot_profiles`), batch voucher generator with 80mm ESC/POS layout & bKash QR payloads, Redis fast-path verification (<0.4ms), captive portal authorization with WISPr VSAs, interim accounting quota consumption.
- **Release 2.0 Roadmap & Evolution Documentation:** Created `docs/11-evolution/PRODUCT_ROADMAP.md`, `TECHNICAL_DEBT.md`, `INCIDENT_POSTMORTEM_RUNBOOK.md`, `POSTMORTEM_TEMPLATE.md`, and `FEEDBACK_TRIAGE_PROCESS.md`.

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
