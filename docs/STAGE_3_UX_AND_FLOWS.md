# Stage 3: UX, User Journeys & Screen Specifications (`docs/03-ux/`)

**Project:** ISP Billing Service & FreeRADIUS Automation  
**Owner:** Tapos | **Date:** 16 November 2026 | **Stage:** Stage 3 — UX & Product Design  

---

## 1. Primary User Journeys & Flows

### Journey 1: NOC Engineer — Zero-Touch Provisioning & Live CoA Kick
1. Opens **NOC Command Center** (`/noc/dashboard`).
2. Selects **Subscribers** -> clicks **New Subscriber** -> assigns `Tariff` (`20M_Home`) and `NAS` (`mikrotik_bras_cluster`).
3. Backend atomically commits `subscribers` + `radcheck` + `radreply` + Redis Fast-Path (`FR-002`).
4. To test or enforce suspension: clicks **Disconnect (PoD)** -> `POST /api/v1/coa/disconnect` sends UDP `3799` -> toast confirms `Disconnect-ACK (<500ms)`.

### Journey 2: End Subscriber — Walled Garden Captive Portal & 1-Tap bKash Pay
1. Expired subscriber opens any HTTP site -> MikroTik Walled Garden NAT redirects to `https://pay.isp-domain.bd/captive`.
2. Captive Portal calls `GET /portal/v1/profile` & `GET /portal/v1/invoices` -> displays unpaid invoice and **Pay with bKash / Nagad** button.
3. Subscriber completes bKash PIN/OTP -> webhook hits `/api/v1/webhooks/bkash` -> `SETNX` + `+30d` + UDP `3799` CoA reset -> UI polls `GET /portal/v1/status` and shows **"Internet Restored (<3s)"**.

### Journey 3: Reseller / POP Partner — Tenant-Isolated Management
1. Reseller signs in via Firebase Auth (`tenant_id` claim) -> sees only their POP subscribers and wallet balance (`FR-001`).

---

## 2. Screen Inventory & Mandatory UI States

| Screen / Route | Primary Role | Key Components | Mandatory Edge States |
| :--- | :--- | :--- | :--- |
| `/noc/dashboard` | NOC / Admin | Active PPPoE counter, Auth/CoA latency KPIs, Gemini AI Diagnose drawer | Loading skeleton, Offline banner, API `503` retry |
| `/subscribers` | NOC / Reseller | Virtualized 1M+ grid, Filter by `status`/`nas_id`, Quick CoA Kick button | Empty search state, `403` cross-tenant block |
| `/tariffs` & `/nas` | Super Admin | Speed profile CRUD (`20M/20M`), NAS IP + WireGuard subnet validator | Form validation (`0.0.0.0/0` warning in prod) |
| `/portal/home` | Subscriber | Live bandwidth chart, Expiry countdown, 1-tap Pay, Support ticket form | Walled Garden banner, Payment pending/failed retry |

## 3. Gate 3 Exit Checklist
- [x] All 4 user journeys (NOC, Subscriber Walled Garden, Reseller, Admin) defined end-to-end.
- [x] Loading, Empty, Error, Offline, and `403` Permission-Denied states specified for every screen.
