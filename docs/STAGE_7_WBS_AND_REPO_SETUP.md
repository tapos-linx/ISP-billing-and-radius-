# Stage 7: Delivery Planning, WBS & Repository Setup (`docs/07-delivery/`)

**Project:** ISP Billing Service & FreeRADIUS Automation  
**Owner:** Tapos | **Date:** 16 November 2026 | **Stage:** Stage 7 — Delivery Planning & Repo Baseline  

---

## 1. Work Breakdown Structure (Vertical Slices)

| Slice | Scope & Deliverables | Target SLA / Gate | Status |
| :--- | :--- | :--- | :--- |
| **Slice 1 (Active)** | Repo setup, `docker-compose.fixed.yml`, `init-citus-shards-complete.sql`, `RadiusSyncService.php`, `SubscriberRadiusSyncTest.php` | Atomic Citus + `radcheck`/`radreply` + Redis sync passes PHPUnit | **In Progress** |
| **Slice 2** | GCE FreeRADIUS 3.2+ (`radiusd.conf`, `sql.conf`, locked `clients.conf`) + Virtual MikroTik CHR lab | `radtest` CLI returns `Access-Accept` in `<10ms` | Pending |
| **Slice 3** | `RadiusCoaService` (UDP `3799` RFC 5176 PoD/CoA) + `POST /api/v1/coa/disconnect` & `/change-speed` | Virtual CHR terminates PPPoE session in `<500ms` | Pending |
| **Slice 4** | bKash/Nagad HMAC-SHA256 webhooks + Redis `SETNX` (`noeviction`) + `00:01 AM` Cloud Scheduler expiry job | Mock MFS webhook restores speed in `<3.0s` | Pending |
| **Slice 5** | React 19 NOC Command Center + Customer Self-Care PWA + Gemini AI Copilot (`/api/v1/ai/diagnose`) | Cloud Run deploy + `verify_ai_endpoints.py` pass | Pending |

---

## 2. Repository Settings & Root Files

### `.gitignore`
```gitignore
.env
.env.*
!.env.example
/vendor/
/node_modules/
/storage/*.key
local.properties
*.log
.DS_Store
```

### Definition of Done (DoD) per Vertical Slice
- [ ] `FR-xxx` & `RTM.csv` acceptance criteria satisfied.
- [ ] Unit/integration tests pass in Docker + Virtual CHR (zero live ISP network impact).
- [ ] No hardcoded secrets; `ci_pipeline.yml` passes lint, tests, and secret scan.
- [ ] Updated artifacts uploaded to [* ISP Billing & FreeRADIUS — Google Ecosystem Master Hub](https://drive.google.com/drive/folders/1B9TfaHAOX7TKuns-pjqmlKiylDiFbqzL) and [Developing ISP Billing Soft](https://gemini.google.com/notebook/5c93f698-45d8-4333-b64f-cd08298d041f).
