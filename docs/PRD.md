# Stage 2: Product Requirements Document (`docs/02-requirements/PRD.md`)

**Project:** ISP Billing Service & FreeRADIUS Automation (Google Ecosystem Edition)  
**Owner:** Tapos (N M Hedyetullah Tapos)  
**Date:** 16 November 2026 | **Stage:** Stage 2 — Requirements Engineering  
**Baseline:** [ISP_Billing_Engineering_Baseline_and_Base_Rule.md](https://docs.google.com/document/d/1K_cbfXsACCiN6xVRPofhfZILkF4HxXleiUCXwXhVJNU/edit)  

---

## 1. Functional Requirements (`FR-001` to `FR-010`)

### `FR-001`: Multi-Tenant Authentication & RBAC
- **Priority:** Must | **Actor:** Super Admin, NOC Engineer, Reseller, Subscriber
- **Description:** Authenticate users via Firebase Auth / Cloud Identity and enforce server-side role permissions (`tenant_id` isolation for Resellers).
- **Acceptance Criteria:**
  - *Given* a Reseller JWT with `tenant_id = 5`, *When* requesting `GET /api/v1/subscribers`, *Then* only rows matching `tenant_id = 5` are returned (`HTTP 200`); accessing another tenant returns `HTTP 403`.

### `FR-002`: Subscriber Provisioning & Atomic RADIUS Sync
- **Priority:** Must | **Actor:** Admin / Reseller / BSS API
- **Description:** Creating or updating a subscriber in Citus (`subscribers`, 128 shards) atomically updates `radcheck`, `radreply` (`Mikrotik-Rate-Limit`, `Framed-Pool`), and Redis 7 Fast-Path cache.
- **Acceptance Criteria:**
  - *Given* valid subscriber + tariff payload, *When* `POST /api/v1/subscribers` executes, *Then* `subscribers`, `radcheck`, and `radreply` commit in one transaction and Redis cache is populated.
  - *Failure Flow:* If DB transaction fails, zero orphan rows are written to `radcheck`/`radreply`.

### `FR-003`: High-Speed PPPoE AAA Authentication & Accounting
- **Priority:** Must | **Actor:** MikroTik CCR / Virtual CHR / BNG
- **Description:** FreeRADIUS 3.2+ (`rlm_redis` + `rlm_sql_postgresql`) authenticates PPPoE sessions on UDP `1812` and records interim accounting every `300s` on UDP `1813`.
- **Acceptance Criteria:**
  - *Given* an active subscriber, *When* `radtest` or Virtual CHR sends `Access-Request`, *Then* FreeRADIUS returns `Access-Accept` with `Mikrotik-Rate-Limit` in `<0.4ms` (Redis hit) or `<10ms` (Citus SQL miss).

### `FR-004`: Dynamic Bandwidth Change & Session Kick (RFC 5176 CoA/PoD)
- **Priority:** Must | **Actor:** NOC Engineer / Billing Worker
- **Description:** Send `CoA-Request` (live speed upgrade/downgrade) or `Disconnect-Request` (PoD) to BNG over UDP `3799` via WireGuard VPN.
- **Acceptance Criteria:**
  - *Given* an active PPPoE session, *When* `POST /api/v1/coa/disconnect` is called, *Then* the BNG receives UDP `3799` PoD and returns `Disconnect-ACK` within `<500ms`.

### `FR-005`: Automated Daily Expiry & Walled Garden Redirect
- **Priority:** Must | **Actor:** Cloud Scheduler (`00:01 AM Asia/Dhaka`)
- **Description:** Daily job `POST /api/v1/jobs/expire-overdue` marks expired accounts, sets `Framed-Pool := walled_garden_pool`, and dispatches PoD kick so reconnects land on the Captive Bill-Pay Portal.
- **Acceptance Criteria:**
  - *Given* a subscriber with `expires_at < NOW()`, *When* the `00:01 AM` job runs, *Then* status becomes `expired`, live session is kicked, and next PPPoE dial-in receives `walled_garden_pool`.

### `FR-006`: bKash Tokenized Checkout & Nagad/SSLCOMMERZ Webhook Settlement
- **Priority:** Must | **Actor:** MFS Gateway / Subscriber
- **Description:** Verify webhook signature (`HMAC-SHA256`) and Execute Payment status, enforce Redis `SETNX` (`noeviction`) idempotency, extend `expires_at` by `+30 days`, and trigger CoA/PoD reset.
- **Acceptance Criteria:**
  - *Given* a valid unpaid invoice and bKash Execute `statusCode = 0000`, *When* webhook arrives, *Then* invoice is marked `PAID`, speed is restored in `<3.0s`, and duplicate webhooks with the same `trxID` return `200 OK` without double-crediting.

### `FR-007`: Tariff & Multi-Vendor NAS Management
- **Priority:** Must | **Actor:** Super Admin / NOC
- **Description:** Manage speed profiles (`20M/20M`, FUP) and NAS devices (MikroTik, Huawei, Juniper) with encrypted RADIUS secrets (`GCP Secret Manager`) and CoA port (`3799`).
- **Acceptance Criteria:**
  - *Given* a registered NAS, *When* `clients.conf` / `nas` table syncs, *Then* only whitelisted WireGuard/BNG IPs are accepted (never `0.0.0.0/0` in production).

### `FR-008`: OLT & GenieACS (TR-069) Telemetry
- **Priority:** Should | **Actor:** NOC Engineer
- **Description:** Poll OLT optical Rx power (`-18` to `-22 dBm`) and GenieACS CPE status via `GET /api/v1/noc/telemetry`.
- **Acceptance Criteria:**
  - *Given* an online ONT, *When* telemetry is requested, *Then* Rx power, ONU serial, and active PPPoE uptime render in the NOC dashboard.

### `FR-009`: Customer Self-Care PWA & Captive Bill-Pay Portal
- **Priority:** Must | **Actor:** End Subscriber
- **Description:** Allow subscribers (active or inside Walled Garden) to view usage, download invoices, pay via bKash/Nagad, and open support tickets (`/portal/v1/*`).
- **Acceptance Criteria:**
  - *Given* an expired subscriber in `walled_garden_pool`, *When* opening any HTTP URL, *Then* they reach the 1-tap bill-pay portal and regain full internet within `<3.0s` after payment.

### `FR-010`: Gemini API NOC Copilot Diagnostics
- **Priority:** Should | **Actor:** NOC Engineer
- **Description:** `POST /api/v1/ai/diagnose` analyzes `radpostauth` rejects, optical power drops, and webhook errors using `GEMINI_API_KEY` (`gemini-2.5-flash` / `gemini-2.5-pro`).
- **Acceptance Criteria:**
  - *Given* a failed auth log snippet, *When* NOC clicks Diagnose, *Then* Gemini returns actionable root cause and CLI/RouterOS check steps.

---

## 2. Non-Functional Requirements (`NFR-001` to `NFR-005`)
- **`NFR-001` (Latency):** Auth `<0.4ms` (Redis) / `<10ms` (SQL); CoA `<500ms`; MFS Unblock `<3.0s`; API P99 `<80ms`.
- **`NFR-002` (Scalability):** Citus PG16 128-shard `subscribers` + co-located/ClickHouse IPDR supporting 1M–10M subscribers.
- **`NFR-003` (Zero-Risk Testing):** 100% of `FR-001`–`FR-010` must pass in Docker + Virtual MikroTik CHR + Mock MFS before touching any live ISP router.
- **`NFR-004` (Reliability):** Redis `noeviction` mandatory; daily automated `pg_dump` and Cloud Run revision rollback.
- **`NFR-005` (Security):** Zero secrets in git/prompts; GCP Secret Manager + HMAC-SHA256 + WireGuard-restricted `clients.conf`.

## 3. Gate 2 Exit Checklist
- [x] All `Must` requirements (`FR-001`–`FR-007`, `FR-009`) are testable with Given/When/Then criteria.
- [x] Out-of-scope items (live network testing, SIP/hotspot vouchers) remain excluded.
- [x] Every requirement is mapped in `RTM.csv`.
