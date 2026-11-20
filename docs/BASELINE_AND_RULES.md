# ISP Billing & FreeRADIUS Automation — Engineering Baseline & Google Ecosystem Base Rule

**প্রজেক্ট:** ISP Billing Service & FreeRADIUS Automation (1M–10M Carrier Scale)  
**আর্কিটেক্ট ও ওনার:** Tapos (N M Hedyetullah Tapos)  
**বেজলাইন সংস্করণ:** v1.0 (16 November 2026)  
**মাস্টার হাব (Google Drive):** [* ISP Billing & FreeRADIUS — Google Ecosystem Master Hub](https://drive.google.com/drive/folders/1B9TfaHAOX7TKuns-pjqmlKiylDiFbqzL)  
**মাস্টার নোটবুক (NotebookLM):** [Developing ISP Billing Soft](https://gemini.google.com/notebook/5c93f698-45d8-4333-b64f-cd08298d041f)  

---

## ১. মূল নীতি ও Google Ecosystem Base Rule

এই ডকুমেন্টটি **ISP Billing & FreeRADIUS Automation** প্রজেক্টের জন্য একক **Engineering Baseline** এবং সমস্ত Google Ecosystem টুল (**Gemini Chat**, **NotebookLM**, **Google Drive**, **Google AI Studio**, **Google Antigravity**, **Gemini CLI**, **Google Cloud**) এর জন্য বাধ্যতামূলক **Base Rule**।

### ১০টি অপারেটিং প্রিন্সিপল (AI-First Engineering)
1. **Plan before code:** Stage 0–6 (Requirements, Architecture, Citus/RADIUS Schema, Security, OpenAPI) অনুমোদন না হওয়া পর্যন্ত বড় ইমপ্লিমেন্টেশন শুরু নয়।
2. **One source of truth:** সমস্ত সিদ্ধান্ত, স্কিমা ও প্রম্পট গিট রিপোজিটরি (`/docs/`) + Drive Master Hub + `Developing ISP Billing Soft` নোটবুকে সিঙ্ক থাকবে।
3. **Small, verifiable increments:** পুরো অ্যাপ একসাথে নয়; ছোট vertical slice (যেমন: `radtest` `<10ms`, `CoA` `<500ms`, `MFS Webhook` `<3s`) তৈরি ও যাচাই করে এগোতে হবে।
4. **Evidence over claims:** "Done" গ্রহণযোগ্য নয়—অবশ্যই টেস্ট লগ, `verify_ai_endpoints.py` আউটপুট, `radtest` ল্যাটেন্সি বা বিল্ড আর্টিফ্যাক্ট থাকতে হবে।
5. **Human approval gates:** ডেস্ট্রাক্টিভ Citus মাইগ্রেশন, প্রোডাকশন ডিপ্লয়মেন্ট, পেমেন্ট/রিফান্ড ও ক্রেডেনশিয়াল রোটেশনের আগে ওনারের (Tapos) অনুমোদন বাধ্যতামূলক।
6. **No invented implementation:** কোনো API, FreeRADIUS মডিউল, বা ENV ভেরিয়েবল অনুমান নয়—অফিশিয়াল ডক বা এক্সিকিউটেবল টেস্ট দিয়ে যাচাই করতে হবে।
7. **Security & privacy by design:** আসল `GEMINI_API_KEY`, bKash/Nagad সিক্রেট, `RADIUS_SECRET` বা গ্রাহকের ডেটা কখনো প্রম্পট বা গিট রিপোজিটরিতে রাখা যাবে না (GCP Secret Manager ও `.env.example` ব্যবহার)।
8. **Traceability:** প্রতিটি Requirement (`FR-xxx`) এর সাথে কোড, টেস্ট ও রিলিজ এভিডেন্স যুক্ত থাকবে।
9. **Reversible changes:** ব্রাঞ্চ, `pg_dump` ব্যাকআপ, মাইগ্রেশন রোলব্যাক ও Blue-Green/Cloud Run রিভিশন রোলব্যাক প্ল্যান থাকতে হবে।
10. **Document continuously:** প্রতিটি আর্কিটেকচার সিদ্ধান্তের সাথে সাথে ADR (`docs/04-architecture/ADR/`) ও নোটবুক আপডেট করতে হবে।

---

## ২. জিরো-রিস্ক আইসোলেটেড টেস্টিং (এক্সিস্টিং লাইভ নেটওয়ার্কে কোনো টেস্ট নয়)

> **বাধ্যতামূলক নিয়ম:** কোনো নতুন কোড, FreeRADIUS পলিসি বা রাউটার স্ক্রিপ্ট **এক্সিস্টিং লাইভ প্রোডাকশন নেটওয়ার্কে ডেপ্লয় করে টেস্ট করার পর সিদ্ধান্ত পরিবর্তন করা যাবে না।** সমস্ত ডিজাইন, প্রোটোকল ও পারফরম্যান্স সিদ্ধান্ত নিচের ৪টি আইসোলেটেড বিকল্পে চূড়ান্ত করতে হবে:

| টেস্টিং লেয়ার | জিরো-রিস্ক বিকল্প টুল / এনভায়রনমেন্ট | যা যাচাই করে সিদ্ধান্ত চূড়ান্ত হবে |
| :--- | :--- | :--- |
| **১. ভার্চুয়াল BRAS / রাউটার** | **MikroTik Cloud Hosted Router (CHR)** (KVM/VirtualBox/Docker) বা GNS3 / Containerlab | PPPoE সার্ভার, VLAN 100, Walled Garden NAT, CGNAT পুল এবং UDP `3799` CoA/PoD কিক (`<500ms`)। |
| **২. AAA ও ডেটাবেজ ল্যাব** | **Docker Compose** (`citus_master`, `citus_worker_1/2`, `redis_cluster` with `noeviction`, `freeradius`) + `radtest`/`radclient` CLI | `<0.4ms` Redis Fast-Path ও `<10ms` Citus SQL `Access-Accept` এবং `Mikrotik-Rate-Limit` VSA। |
| **৩. ফিনটেক ও এন্ডপয়েন্ট সিমুলেটর** | **Mock MFS Webhook Runner** + `verify_ai_endpoints.py` + Google AI Studio Preview | bKash/Nagad HMAC-SHA256 সিগনেচার, Redis `SETNX` Idempotency এবং `<3s` অটো-রিকানেকশন। |
| **৪. হার্ডওয়্যার স্টেজ ক্যানারি** | শুধুমাত্র **১টি আইসোলেটেড টেস্ট VLAN (যেমন VLAN 999)** + ১টি টেস্ট XPON ONT | লাইভ গ্রাহকদের কোনো প্রভাব ছাড়াই ৭২ ঘণ্টার অপটিক্যাল ও TR-069 (GenieACS) ভেরিফিকেশন। |

---

## ৩. ১২-স্টেজ এন্ড-টু-এন্ড প্রজেক্ট লাইফসাইকেল ও গেট ম্যাট্রিক্স (ISP Billing Edition)

| Stage | মূল কাজ (Playbook) | ISP Billing & FreeRADIUS ডেলিভারেবল | Exit Gate (পরের ধাপের শর্ত) |
| :--- | :--- | :--- | :--- |
| **0. Project Intake** | সমস্যা, ইউজার ও MVP বাউন্ডারি নির্ধারণ | `docs/00-project/PROJECT_BRIEF.md`, `GOALS_AND_NON_GOALS.md` | টার্গেট স্কেল (1M–10M) ও MVP স্কোপ স্পষ্ট |
| **1. Discovery & Research** | ডোমেইন, টেকনোলজি, BTRC ও MFS রিসার্চ | `docs/01-discovery/RESEARCH_REPORT.md`, `TECHNOLOGY_EVALUATION.md`, `ASSUMPTIONS_REGISTER.md` (Label: `VERIFIED` / `ASSUMPTION`) | BTRC IPDR, MFS লিমিট ও GCP Cloud Run vs GCE UDP সীমাবদ্ধতা যাচাইকৃত |
| **2. Requirements** | Functional (`FR-xxx`) ও Non-Functional (`<10ms` Auth, `<500ms` CoA, `<3s` Unblock) | `docs/02-requirements/PRD.md`, `SRS.md`, `RTM.csv` | প্রতিটি Must requirement testable ও Given/When/Then ফরম্যাটে |
| **3. UX & Product Design** | NOC Admin, Reseller, Customer PWA ও Captive Portal ফ্লো | `docs/03-ux/USER_JOURNEYS.md`, `USER_FLOWS.md`, `SCREEN_SPECIFICATIONS.md` | লোডিং, এরর, অফলাইন ও Walled Garden স্টেট নির্দিষ্ট |
| **4. Architecture** | Hybrid GCP (Cloud Run + GCE FreeRADIUS + WireGuard) ও C4 ডায়াগ্রাম + ADR | `docs/04-architecture/HLD.md`, `C4_DIAGRAMS/`, `ADR/ADR-0001-*.md` | Failure modes ও trust boundaries রিভিউকৃত |
| **5. Data & API Design** | Citus 128/256 Shard ERD (`init-citus-shards.sql` + `radcheck`/`radreply`/`nas`/`invoices`) ও OpenAPI | `docs/05-data/ERD.*`, `DATA_DICTIONARY.md`, `docs/05-api/openapi.yaml` | Schema ও API contracts স্থির; কোনো অস্পষ্টতা নেই |
| **6. Security & Compliance** | STRIDE Threat Model, RBAC, HMAC-SHA256, `clients.conf` IP লক, GCP Secret Manager | `docs/06-security/THREAT_MODEL.md`, `PERMISSION_MATRIX.md` | জিরো হার্ডকোডেড সিক্রেট; সার্ভার-সাইড অথোরাইজেশন |
| **7. Delivery Planning** | WBS, DoR/DoD, রিপো সেটআপ (`CLAUDE.md`, `GEMINI.md`, `.cursorrules`, `.env.example`) | `docs/07-delivery/ROADMAP.md`, `WBS.md`, `DEFINITION_OF_DONE.md` | AI Studio, Antigravity ও Gemini CLI একই কনটেক্সটে রেডি |
| **8. Build (Vibe Loop)** | १०-ধাপের Execution Loop (Context → Inspect → Plan → Approve → Implement → Verify → Commit) | Vertical slices: AAA Core → BSS API → CoA/PoD → MFS Webhooks | প্রতিটি স্লাইস ইউনিট/ইন্টিগ্রেশন টেস্ট ও ডিফ রিভিউ পাস |
| **9. QA & Hardening** | Docker + MikroTik CHR + `radtest` + Mock MFS + `verify_ai_endpoints.py` লোড ও সিকিউরিটি টেস্ট | `docs/08-qa/TEST_STRATEGY.md`, `RELEASE_TEST_REPORT.md` | ক্রিটিক্যাল বাগ শূন্য; সিমুলেটেড SLA পাস |
| **10. Deployment** | Cloud Run CI/CD + GCE FreeRADIUS + WireGuard + Rollback Runbook | `docs/09-ops/CI_CD.md`, `DEPLOYMENT_RUNBOOK.md`, `ROLLBACK_PLAN.md` | স্টেজিং সাইন-অফ ও ব্যাকআপ রিস্টোর ভেরিফাইড |
| **11. Production Ops / Cloud Staging** | Cloud Staging (GCP/AWS) — **DEFERRED (Using Local Docker + On-Prem Path)**; scripts intact | `infra/cloudbuild.yaml`, `deploy_stage11_gcp.ps1`, `infra/aws/` | লোকাল ডকার ও অন-প্রিম পাথ দিয়ে সম্পূর্ণ ভেরিফাইড; ক্লাউড বিলিং স্থগিত |
| **12. Evolution** | ফিডব্যাক ট্রায়াজ, টেকনিক্যাল ডেট, পোস্টমর্টেম ও রোডম্যাপ আপডেট | `docs/11-evolution/PRODUCT_ROADMAP.md`, `TECHNICAL_DEBT.md`, `CHANGELOG.md` | পরবর্তী ইটারেশন অনুমোদিত |

---

## ৪. Google Ecosystem-এর জন্য বাধ্যতামূলক ডকুমেন্টেশন স্ট্রাকচার ও এআই রোল ম্যাপ

সমস্ত রিপোজিটরি (`ISP-billing-and-radius` / Google AI Studio / Antigravity) নিচের `/docs/` স্ট্রাকচার অনুসরণ করবে:
- `docs/00-project/` থেকে `docs/11-evolution/` (Stages 0–12 অনুযায়ী)
- রুট ফাইল: `README.md`, `GEMINI.md`, `CLAUDE.md`, `.cursorrules`, `.env.example`, `CHANGELOG.md`

### ফিচার তৈরির ১০-ধাপের বাধ্যতামূলক Vibe Coding Loop (Stage 8)
1. **Context:** AI-কে `/docs/` ও সংশ্লিষ্ট স্কিমা দিন।
2. **Inspect:** কোড পরিবর্তনের আগে বর্তমান ফাইল পড়তে বলুন।
3. **Plan:** পরিবর্তিত ফাইল, ঝুঁকি ও টেস্ট প্ল্যান লিখতে বলুন।
4. **Approve:** আপনি (Tapos) প্ল্যান অনুমোদন করবেন।
5. **Implement:** সীমিত স্কোপে কোড লিখুন।
6. **Explain:** ডেটা ফ্লো ও ব্যর্থতার সম্ভাবনা (failure modes) ব্যাখ্যা করান।
7. **Verify:** `radtest`, লিন্ট, টাইপচেক ও `verify_ai_endpoints.py` চালান।
8. **Review:** সিকিউরিটি ও মাইগ্রেশন ডিফ যাচাই করুন।
9. **Document:** `ADR`, `CHANGELOG.md`, ও NotebookLM/Drive আপডেট করুন।
10. **Commit:** ভেরিফাইড কোড কমিট করে পরের স্লাইসে যান।
