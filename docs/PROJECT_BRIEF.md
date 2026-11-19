# Stage 0: Project Brief (`docs/00-project/PROJECT_BRIEF.md`)

**প্রজেক্টের নাম:** ISP Billing Service & FreeRADIUS Automation (Google Ecosystem Edition)  
**আর্কিটেক্ট ও ওনার:** Tapos (N M Hedyetullah Tapos)  
**তারিখ:** ১৬ নভেম্বর ২০২৬ | **স্টেজ:** Stage 0 — Project Intake & Problem Definition  
**মাস্টার হাব:** [* ISP Billing & FreeRADIUS — Google Ecosystem Master Hub](https://drive.google.com/drive/folders/1B9TfaHAOX7TKuns-pjqmlKiylDiFbqzL)  

---

## ১. এক বাক্যে সমস্যা (Problem Statement)
বাংলাদেশের আইএসপিগুলোর প্রচলিত বিলিং, গ্রাহক CRM, FreeRADIUS AAA, MikroTik/BNG কন্ট্রোল এবং bKash/Nagad পেমেন্ট সিস্টেম আলাদা সাইলোতে থাকায় ম্যানুয়াল রিকনসিলিয়েশন ও সংযোগ বিচ্ছিন্ন/পুনঃসংযোগে বিলম্ব হয়—তাই একটি ইউনিফায়েড, অটোমেটেড, ক্যারিয়ার-স্কেল (১M–১০M সাবস্ক্রাইবার) Google Ecosystem-ভিত্তিক BSS/OSS প্ল্যাটফর্ম তৈরি করা প্রয়োজন।

## ২. টার্গেট ইউজার ও স্টেকহোল্ডার (Stakeholders)
| রোল | মূল কাজ | সিস্টেম ইন্টারফেস |
| :--- | :--- | :--- |
| **Super Admin / Owner (Tapos)** | ট্যারিফ, রিসেলার, ফিন্যান্স, আর্কিটেকচার অনুমোদন | Google AI Studio / FilamentPHP v3 Command Center |
| **NOC Engineer** | লাইভ PPPoE সেশন, OLT অপটিক্যাল পাওয়ার (`-18` থেকে `-22 dBm`), CoA/PoD কিক, Gemini Copilot ডায়াগনসিস | React 19 NOC Dashboard + GenieACS TR-069 |
| **Reseller / POP Partner** | নিজস্ব সাবস্ক্রাইবার ও ওয়ালেট ব্যালেন্স পরিচালনা | Multi-Tenant Reseller Portal (`tenant_id` আইসোলেটেড) |
| **End Subscriber (গ্রাহক)** | রিয়েল-টাইম ইউসেজ দেখা, bKash/Nagad বিল পে, Walled Garden পোর্টাল | Customer Self-Care PWA + Captive Bill-Pay Portal |

## ৩. Goals & Non-Goals (MVP vs Carrier Scale)

### Goals (যা অবশ্যই থাকবে)
- **AAA Fast-Path ও SQL:** FreeRADIUS 3.2+ (`rlm_sql` + Redis 7 `noeviction`) দিয়ে `<0.4ms` ক্যাশ এবং `<10ms` SQL অথেনটিকেশন।
- **ডাইনামিক CoA/PoD:** [RFC 5176](https://www.freeradius.org/rfc/rfc5176.html) অনুযায়ী UDP `3799` পোর্টে `<500ms`-এ লাইভ সেশন ডিসকানেক্ট ও স্পিড পরিবর্তন।
- **জিরো-টাচ MFS পেমেন্ট:** bKash Tokenized Checkout, Nagad ও SSLCOMMERZ ওয়েবহুক দিয়ে `<3.0 সেকেন্ডে` স্বয়ংক্রিয় আনব্লক ও `+30 দিন` মেয়াদ বৃদ্ধি।
- **হাইব্রিড Google Ecosystem:** Google Cloud Run (HTTP API ও ওয়েবহুক) + GCE Ubuntu 24.04 (UDP FreeRADIUS + WireGuard VPN) + Citus PG16 (128/256 শার্ড)।
- **জিরো-রিস্ক আইসোলেটেড টেস্টিং:** এক্সিস্টিং লাইভ নেটওয়ার্কে কোনো টেস্ট না করে Docker + Virtual MikroTik CHR + Mock MFS স্যান্ডবক্সে চূড়ান্ত সিদ্ধান্ত।

### Non-Goals (প্রাথমিক ধাপে যা বাদ থাকবে)
- প্রোডাকশন লাইভ গ্রাহকদের রাউটারে পরীক্ষামূলক স্ক্রিপ্ট চালানো।
- ওয়াই-ফাই হটস্পট ভাউচার ও ভয়েস/SIP ট্রাঙ্কিং বিলিং (Release 2-তে যুক্ত হবে)।

## ৪. সাফল্যের মাপকাঠি (Success Metrics / SLA)
- **Auth Latency:** `< 0.4ms` (Redis Hit), `< 10ms` (Citus SQL `Access-Accept`)।
- **CoA/PoD Disconnect:** `< 500ms` (UDP `3799` `Disconnect-ACK`)।
- **MFS Auto-Reconnect:** `< 3.0s` (Webhook থেকে পূর্ণ ব্যান্ডউইথ রিস্টোর)।
- **Pilot Exit Gate:** ভার্চুয়াল CHR ও আইসোলেটেড টেস্ট VLAN-এ ৭২ ঘণ্টা জিরো প্যাকেট ড্রপ।

## ৫. Gate 0 যাচাই (Sign-off Checklist)
- [x] এক বাক্যে সমস্যা ও টার্গেট ইউজার নির্ধারিত।
- [x] Goals, Non-Goals ও SLA মেট্রিক্স চূড়ান্ত।
- [x] লাইভ নেটওয়ার্কের বদলে আইসোলেটেড ল্যাব টেস্টিং নীতি গৃহীত।
