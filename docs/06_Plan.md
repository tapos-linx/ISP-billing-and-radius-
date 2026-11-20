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
