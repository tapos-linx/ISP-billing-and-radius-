# Release 2.0 Product Roadmap: Carrier Expansion & Day-2 Operations

**Project:** ISP Billing Service & FreeRADIUS Automation (Carrier Scale 1M–10M)  
**Lead Architect & Owner:** Tapos (N M Hedyetullah Tapos)  
**Target Release:** Release 2.0 (Q1–Q2 2027)  
**Document Status:** Approved Architecture Draft  
**Baseline Reference:** [docs/BASELINE_AND_RULES.md](../BASELINE_AND_RULES.md) (Stage 12 / 13 Evolution)

---

## 1. Executive Summary & Vision

Following the successful completion and verification of the Stage 1–12 baseline (FreeRADIUS 3.2, Citus 128-shard PostgreSQL, Redis 7 `noeviction`, Keepalived VIP `10.10.0.10`, MikroTik RouterOS v7 RFC 3576 CoA, and bKash/Nagad MFS auto-settlement), **Release 2.0** drives the platform's commercial and technological expansion.

Release 2.0 expands the service envelope beyond fixed-broadband PPPoE into **public Wi-Fi hotspot monetization**, **VoIP/SIP telephony billing**, **GenieACS TR-069 proactive optical management**, and **ClickHouse 2-year IPDR log storage** to achieve compliance with BTRC 730-day carrier mandates at scale (>50,000 req/s).

---

## 2. Release 2.0 Epic Breakdown

### Epic 1: Wi-Fi Hotspot Voucher Management System (Captive Portal)
* **Goal:** Enable rapid deployment of prepaid Wi-Fi hotspots across commercial zones, universities, and public venues with instant bKash/Nagad purchase and ephemeral voucher printing.
* **Architecture:**
  - **Captive Portal Engine:** CoovaChilli and MikroTik Hotspot (`/ip hotspot`) integration.
  - **RADIUS Attributes:** `WISPr-Bandwidth-Max-Down`, `WISPr-Bandwidth-Max-Up`, `Session-Timeout`, and `Idle-Timeout`.
  - **Voucher Life-Cycle:**
    - State machine: `GENERATED` $\rightarrow$ `PURCHASED` $\rightarrow$ `ACTIVE` $\rightarrow$ `DEPLETED` / `EXPIRED`.
    - Batch voucher generation with thermal printer POS export (80mm ESC/POS format with QR code).
  - **Fast-Path AAA:** Ephemeral voucher validation in Redis fast-path (<0.4ms) with automatic quota burn tracking.

```sql
-- Wi-Fi Hotspot Voucher Schema (Release 2.0 Preview)
CREATE TABLE IF NOT EXISTS hotspot_vouchers (
    id BIGSERIAL PRIMARY KEY,
    code VARCHAR(16) NOT NULL UNIQUE,
    batch_id VARCHAR(32) NOT NULL,
    profile_id INT NOT NULL,
    time_limit_seconds INT DEFAULT 3600,
    data_limit_bytes BIGINT DEFAULT 1073741824, -- 1 GB
    price_bdt NUMERIC(10, 2) NOT NULL,
    status VARCHAR(20) DEFAULT 'GENERATED',
    first_login_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_vouchers_code ON hotspot_vouchers(code);
```

---

### Epic 2: VoIP / SIP Call Rating & Trunking Billing Engine
* **Goal:** Provide unified wholesale and retail voice billing for ISP ISP-telephony and IP-PBX customers.
* **Architecture:**
  - **Call Control Integration:** FreeSWITCH ESL (Event Socket Library) and Kamailio SIP proxy.
  - **CDR Ingestion:** Real-time RFC 3261 Call Detail Record (CDR) rating engine.
  - **E.164 Prefix Rating:** Longest-prefix matching algorithm for national (PSTN/Mobile) and international voice termination.
  - **Prepaid Credit Reservation:** Real-time balance locking using Redis Lua script during `INVITE`, release/deduct on `BYE`.

```sql
-- SIP CDR and Voice Rating Schema (Release 2.0 Preview)
CREATE TABLE IF NOT EXISTS sip_cdrs (
    id BIGSERIAL PRIMARY KEY,
    call_id VARCHAR(128) NOT NULL UNIQUE,
    caller_id VARCHAR(32) NOT NULL,
    callee_id VARCHAR(32) NOT NULL,
    destination_prefix VARCHAR(16) NOT NULL,
    duration_seconds INT NOT NULL,
    billable_seconds INT NOT NULL,
    rate_per_minute NUMERIC(10, 4) NOT NULL,
    total_cost NUMERIC(10, 4) NOT NULL,
    hangup_cause VARCHAR(64),
    start_time TIMESTAMPTZ NOT NULL,
    answer_time TIMESTAMPTZ,
    end_time TIMESTAMPTZ NOT NULL
);
CREATE INDEX idx_sip_cdrs_caller ON sip_cdrs(caller_id, start_time);
```

---

### Epic 3: ClickHouse Carrier IPDR & Accounting Telemetry Storage
* **Goal:** Offload high-velocity accounting (`radacct`) and NAT translation logs from Citus PostgreSQL to ClickHouse, scaling to 100,000+ events/sec while complying with the BTRC 730-day retention mandate.
* **Architecture:**
  - **Ingestion Pipeline:** FreeRADIUS $\rightarrow$ RabbitMQ `radius.accounting` exchange $\rightarrow$ Vector / Logstash $\rightarrow$ ClickHouse buffer table.
  - **Storage Optimization:** Columnar compression with `LZ4` and `ZSTD`, reducing 2-year log storage footprint by >85%.
  - **Sub-Second Forensic Search:** Instant BTRC IPDR queries by Source IP, Destination IP, Port, and Timestamp.

```sql
-- ClickHouse 2-Year BTRC IPDR Schema (Release 2.0 Preview)
CREATE TABLE IF NOT EXISTS btrc_ipdr_records (
    event_time DateTime CODEC(DoubleDelta, LZ4),
    username LowCardinality(String),
    framed_ip IPv4 CODEC(LZ4),
    nas_ip IPv4 CODEC(LZ4),
    public_nat_ip IPv4 CODEC(LZ4),
    public_port_start UInt16,
    public_port_end UInt16,
    input_octets UInt64 CODEC(T64, LZ4),
    output_octets UInt64 CODEC(T64, LZ4),
    acct_session_id String CODEC(ZSTD),
    calling_station_id String CODEC(ZSTD)
) ENGINE = MergeTree()
PARTITION BY toYYYYMM(event_time)
ORDER BY (framed_ip, event_time, username)
TTL event_time + INTERVAL 730 DAY;
```

---

### Epic 4: GenieACS TR-069 Optical Telemetry & Auto-Provisioning
* **Goal:** Direct fiber monitoring and automated zero-touch provisioning of subscriber XPON ONTs.
* **Capabilities:**
  - **Optical Power Monitoring:** Live telemetry polling of Rx/Tx optical power. Warning threshold at `-22 dBm`; critical threshold at `-25 dBm`.
  - **Zero-Touch Provisioning (ZTP):** Auto-push PPPoE credentials and Wi-Fi SSID upon new ONT discovery.
  - **Remote Diagnostics:** Gemini Copilot integration to diagnose high packet drop vs physical fiber attenuation.

---

## 3. Milestone Delivery Timeline

| Milestone | Target Date | Scope / Deliverables | Success Criteria |
| :--- | :---: | :--- | :--- |
| **M2.1** | Month 1 | Wi-Fi Hotspot Voucher Engine & Captive Portal | Sub-second voucher generation; bKash QR instant purchase |
| **M2.2** | Month 2 | ClickHouse IPDR Cluster Ingestion Pipeline | Ingest 50,000 req/s with <1s query on 2-year data |
| **M2.3** | Month 3 | GenieACS TR-069 Optical Monitoring & Alerts | Auto-provision ONT in <30s; alert when optical power < -22 dBm |
| **M2.4** | Month 4 | VoIP / SIP Trunking & Real-Time CDR Rating | Real-time prepaid credit deduction; <50ms rating latency |
| **M2.5** | Month 5 | Release 2.0 Pilot & Final Operator Handover | 100% green pilot in test lab; complete operator training |
