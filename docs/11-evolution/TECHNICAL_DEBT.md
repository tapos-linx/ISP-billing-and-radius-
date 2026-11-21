# Technical Debt Register & Remediation Strategy

**Project:** ISP Billing Service & FreeRADIUS Automation (Carrier Scale 1M–10M)  
**Lead Architect & Owner:** Tapos (N M Hedyetullah Tapos)  
**Document Status:** Active Engineering Baseline  
**Baseline Reference:** [docs/BASELINE_AND_RULES.md](../BASELINE_AND_RULES.md) (Stage 12 / 13 Evolution)

---

## 1. Principles of Technical Debt Management

Technical debt incurred during the rapid development of Stages 1–12 was deliberately taken to validate core business value (Citus 128-shard architecture, sub-second RFC 3576 CoA, bKash/Nagad instant settlement, and zero-downtime cutover).

This register documents every known architectural concession, trade-off, and scalability boundary along with a systematic remediation roadmap to maintain carrier-grade 99.999% availability.

---

## 2. Technical Debt Catalog

| ID | Title | Category | Severity | Complexity | Target Milestone | Status |
| :--- | :--- | :--- | :---: | :---: | :---: | :---: |
| **TD-001** | `radacct` Table Scaling in Citus PostgreSQL | Database | **HIGH** | Medium | M2.2 (Release 2.0) | Accepted / Scheduled |
| **TD-002** | Redis Fast-Path Memory Footprint at 10M Scale | Caching / AAA | **MEDIUM** | Medium | M2.2 (Release 2.0) | Monitored |
| **TD-003** | Cloud Run UDP Protocol Limitation for FreeRADIUS | Cloud / Infra | **LOW** | High | M2.5 (Release 2.0) | Documented / Isolated |
| **TD-004** | Synchronous Accounting Inserts in FreeRADIUS | AAA / Concurrency| **HIGH** | Medium | M2.2 (Release 2.0) | Planned |
| **TD-005** | PWA Service Worker Background Sync & Offline Mode| Frontend / Mobile | **LOW** | Low | M2.1 (Release 2.0) | In Progress |
| **TD-006** | Hardware-in-the-Loop Automated Containerlab CI | QA / CI/CD | **MEDIUM** | High | M2.3 (Release 2.0) | Proposed |

---

## 3. Detailed Debt Analysis & Remediation Plans

### TD-001: `radacct` Table Scaling in Citus PostgreSQL
* **Context:** Currently, FreeRADIUS writes interim accounting updates directly into PostgreSQL `radacct` tables partitioned by month.
* **Problem:** At 1,000,000+ subscribers sending interim updates every 300 seconds, write velocity exceeds 3,300 writes/sec. During a fiber outage or reconnections, bursts can reach 25,000 writes/sec, causing write amplification and WAL bloat on the coordinator.
* **Remediation:** Implement **ClickHouse** columnar storage for historical accounting records and NAT IPDR logs. FreeRADIUS will publish accounting packets to a high-throughput message queue (RabbitMQ / Kafka), which is bulk-inserted into ClickHouse.

---

### TD-002: Redis Fast-Path Memory Footprint at 10M Scale
* **Context:** Redis operates with `maxmemory-policy: noeviction` to guarantee zero-loss session state and instant subscriber lookup (<0.4ms).
* **Problem:** Each cached subscriber credential and active session occupies ~600 bytes. At 10,000,000 subscribers, Redis memory requirement reaches ~6–8 GB. If RAM is exceeded, write requests (`SETNX`, session updates) will fail.
* **Remediation:** Implement Redis Cluster sharding (3 masters + 3 replicas) across dedicated nodes or adopt KeyDB / Redis-on-Flash for cold subscriber profiles while keeping active online sessions in high-speed RAM.

---

### TD-003: Cloud Run UDP Protocol Limitation for FreeRADIUS
* **Context:** Google Cloud Run natively supports HTTP/1.1, HTTP/2, and gRPC over TCP. Standard RADIUS operates on UDP 1812 (Auth), UDP 1813 (Acct), and UDP 3799 (CoA).
* **Problem:** FreeRADIUS cannot terminate carrier UDP traffic directly on serverless Cloud Run without an intermediate UDP proxy or VM-based gateway.
* **Remediation:** As verified in Stage 11, utilize Google Compute Engine (GCE) e2-micro / e2-medium or on-premises bare-metal instances running native FreeRADIUS 3.2+ with Keepalived VRRP and WireGuard site-to-site tunnels.

---

### TD-004: Synchronous Accounting Inserts in FreeRADIUS
* **Context:** FreeRADIUS uses `rlm_sql` which opens a connection pool to Citus PostgreSQL.
* **Problem:** Slow disk I/O on the database worker during backup operations can tie up FreeRADIUS worker threads waiting for accounting `UPDATE` acknowledgments, temporarily increasing authentication latency.
* **Remediation:** Decouple accounting via FreeRADIUS `rlm_rest` or `rlm_exec` to asynchronous RabbitMQ worker queues. Authentication remains high-priority direct fast-path via Redis and Citus read-replicas.

---

### TD-005: PWA Service Worker Background Sync & Offline Mode
* **Context:** The Customer Self-Care PWA provides billing balance, usage stats, and online payment flows.
* **Problem:** When an expired customer is redirected to the Walled Garden, intermittent cellular data or DNS changes can cause transient fetch errors before the captive portal captive detection completes.
* **Remediation:** Implement Workbox IndexedDB offline storage for static bills, cached profile metadata, and graceful offline fallback screens.

---

### TD-006: Hardware-in-the-Loop Automated Containerlab CI
* **Context:** The current CI/CD runs 36 PHPUnit tests, static analysis, and simulated Python radclient scripts.
* **Problem:** Testing against physical MikroTik CCR hardware requires manual VLAN 999 patch cords or physical lab access.
* **Remediation:** Integrate **Containerlab** with MikroTik RouterOS CHR container images into GitHub Actions runners to execute automated end-to-end PPPoE dial-in, CoA kicks, and queue speed tests on every pull request.
