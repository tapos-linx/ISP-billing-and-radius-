# Stage 4: High-Level Architecture & ADRs (`docs/04-architecture/`)

**Project:** ISP Billing Service & FreeRADIUS Automation  
**Owner:** Tapos | **Date:** 16 November 2026 | **Stage:** Stage 4 — System Architecture & Technical Design  

---

## 1. High-Level Design (C4 Container Boundaries)

1. **Edge & Optical Plane (On-Premises / Virtual CHR Lab):**
   - XPON ONT -> GPON OLT (10G SFP+ VLAN 100) -> MikroTik CCR / Huawei NE8000 / Virtual CHR.
   - Communicates exclusively over **WireGuard VPN** for RADIUS (`1812/1813/3799 UDP`) and RouterOS REST API (`443 TCP`).
2. **AAA & Telemetry Plane (Google Compute Engine — Ubuntu 24.04 LTS):**
   - Runs **FreeRADIUS 3.2+** (`rlm_redis` + `rlm_sql_postgresql`), **GenieACS (TR-069)**, and **Zabbix 7.0 / Prometheus**.
3. **BSS / OSS & Webhook Plane (Google Cloud Run):**
   - Runs **React 19 + TypeScript NOC UI**, **Laravel 11 / Express BSS API**, **MFS Webhooks**, and **Gemini AI Copilot** (`GEMINI_API_KEY` via GCP Secret Manager).
4. **Data & Async Queue Plane:**
   - **Citus 12.1 + PostgreSQL 16** (128 shards `subscribers`, reference tables `tariffs`/`nas`), **Redis 7** (`noeviction`), **RabbitMQ / Cloud Tasks**, and **ClickHouse** (2-year BTRC IPDR/CGNAT logs).

---

## 2. Architecture Decision Records (ADRs)

### `ADR-0001`: Hybrid Cloud Run (HTTP/MFS) + GCE VM (UDP FreeRADIUS)
- **Status:** Accepted | **Date:** 2026-11-16
- **Context:** Cloud Run only accepts HTTP/HTTPS/gRPC and does not support inbound UDP (`1812/1813/3799`).
- **Decision:** Host stateless BSS APIs and MFS webhooks on Cloud Run (auto-scaling), and host FreeRADIUS 3.2+ on a GCE Ubuntu 24.04 LTS VM connected via VPC Connector and WireGuard VPN.
- **Consequences:** Preserves serverless scaling for bKash/Nagad bursts while guaranteeing `<10ms` UDP AAA and `<500ms` CoA/PoD.

### `ADR-0002`: Enforce Redis `noeviction` + `SETNX` for MFS Webhooks
- **Status:** Accepted | **Date:** 2026-11-16
- **Context:** Uploaded `docker-compose.yml` used `--maxmemory-policy allkeys-lru`, which risks evicting idempotency keys or queued CoA jobs under memory pressure.
- **Decision:** Mandate `--maxmemory-policy noeviction` in both local Docker and GCP Memorystore, paired with `SETNX webhook:<trx_id>` and HMAC-SHA256 verification.

### `ADR-0003`: Zero-Risk Virtual CHR + Docker Lab Before Live Network
- **Status:** Accepted | **Date:** 2026-11-16
- **Context:** Testing experimental RADIUS/CoA changes on the live ISP network risks subscriber outages.
- **Decision:** Require 100% pass of `FR-001`–`FR-010` against Docker Citus/Redis/FreeRADIUS + Virtual MikroTik CHR + Mock MFS before any hardware cutover.

## 3. Gate 4 Exit Checklist
- [x] Container boundaries, trust zones (WireGuard + GCP VPC + Secret Manager), and failure isolation defined.
- [x] Core architectural trade-offs documented in `ADR-0001` through `ADR-0003`.
