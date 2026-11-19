# Stage 6: Security, STRIDE Threat Model & RBAC Matrix (`docs/06-security/`)

**Project:** ISP Billing Service & FreeRADIUS Automation  
**Owner:** Tapos | **Date:** 16 November 2026 | **Stage:** Stage 6 — Security, Privacy & Compliance by Design  

---

## 1. STRIDE Threat Model & Mitigations

| Threat (STRIDE) | Attack Surface | Mitigation Enforced |
| :--- | :--- | :--- |
| **Spoofing** | Rogue NAS sending fake `Access-Request` to `clients.conf` (`0.0.0.0/0`) | Lock `clients.conf` `ipaddr` to WireGuard VPN subnet (`10.10.0.0/24`); unique per-NAS `RADIUS_SECRET` in GCP Secret Manager. |
| **Tampering** | Forged bKash/Nagad payment webhook payloads | Verify `HMAC-SHA256` signature + server-side bKash Execute Payment (`statusCode = 0000`) before crediting. |
| **Repudiation / Replay** | Duplicate MFS webhook replay causing double `+30d` extension | Redis `SETNX webhook:<trx_id>` with `--maxmemory-policy noeviction` + immutable `payment_webhooks` audit log. |
| **Information Disclosure** | `GEMINI_API_KEY`, DB passwords, or customer PII in prompts/git | Zero secrets in git/prompts; GCP Secret Manager injection at Cloud Run runtime; `radcheck` encrypted/hashed where NAS supports. |
| **Denial of Service** | Reconnect storms (150k+ auth/sec) or webhook floods | Redis `<0.4ms` Fast-Path cache, Cloud Run HPA, rate-limiting on `/webhooks/*` and `/portal/v1/*`. |
| **Elevation of Privilege** | Reseller accessing another `tenant_id`'s subscribers | Server-side Firebase Auth custom claims + mandatory `WHERE tenant_id = ?` Citus query scoping (`HTTP 403` on mismatch). |

---

## 2. RBAC Permission Matrix

| Resource / Action | Super Admin | NOC Engineer | Reseller (`tenant_id`) | Subscriber |
| :--- | :---: | :---: | :---: | :---: |
| Manage Tariffs & Global NAS | Allow | Read | Deny | Deny |
| Provision / Edit Subscribers | Allow (All) | Allow (All) | Allow (Own Tenant) | Deny |
| Trigger CoA / PoD (`3799`) | Allow | Allow | Own Tenant Only | Deny |
| View OLT / GenieACS Telemetry | Allow | Allow | Own Tenant Only | Own ONT Only |
| Pay Invoices & View Usage | Allow | Read | Own Wallet | Own Account Only |

## 3. Gate 6 Exit Checklist
- [x] Critical/high threats (`clients.conf` `0.0.0.0/0`, Redis `allkeys-lru`, webhook replay) mitigated.
- [x] Server-side RBAC matrix and GCP Secret Manager rules defined.
