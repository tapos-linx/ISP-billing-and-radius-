# Stage 1: Discovery, Research & Feasibility (`docs/01-discovery/STAGE_1_DISCOVERY_RESEARCH.md`)

**প্রজেক্ট:** ISP Billing Service & FreeRADIUS Automation  
**রিসার্চ তারিখ:** ১৬ নভেম্বর ২০২৬ | **স্ট্যাটাস:** Stage 1 — Discovery, Technology Evaluation & Assumptions Register  

---

## ১. টেকনোলজি ইভ্যালুয়েশন ও ভেরিফায়েড ডকুমেন্টেশন (Research Report)

প্রতিটি টেকনিক্যাল দাবি অফিশিয়াল ডকুমেন্টেশন ও সোর্স লিংকসহ যাচাই করা হয়েছে:

| টেকনোলজি / ডোমেইন | যাচাইকৃত তথ্য (Verified Findings) | আমাদের প্রজেক্টে সিদ্ধান্ত | Confidence & Source |
| :--- | :--- | :--- | :--- |
| **Google Cloud Run (UDP সীমাবদ্ধতা)** | Cloud Run কনটেইনার কন্ট্রাক্ট অনুযায়ী শুধুমাত্র HTTP/HTTPS/gRPC ট্রাফিক সমর্থিত; ইনকামিং **UDP পোর্ট** সমর্থিত নয়। | BSS API ও MFS ওয়েবহুক **Cloud Run**-এ চলবে; কিন্তু FreeRADIUS-এর UDP `1812/1813/3799` চলবে **GCE Ubuntu 24.04 VM**-এ। | `VERIFIED` (2026-11-16) — [Cloud Run Container Contract](https://docs.cloud.google.com/run/docs/container-contract), [Cloud Run UDP Info](https://stackoverflow.com/questions/59863586/google-cloud-run-udp-ports-not-supported-anymore) |
| **GCE UDP ও Network LB** | Google Compute Engine VM-এ ফায়ারওয়াল রুল দিয়ে ইনবাউন্ড UDP গ্রহণ করা যায়; তবে Load Balancer ব্যবহার করলে সোর্স-আইপি ম্যাচিংয়ের জন্য `nftables`/NAT পলিসি বা সরাসরি Static IP/WireGuard লাগে। | প্রথম ধাপে GCE VM-এর Static IP + **WireGuard VPN** দিয়ে সরাসরি MikroTik CCR থেকে UDP `1812/1813/3799` টানেল করা হবে। | `VERIFIED` (2026-11-16) — [GCP UDP Load Balancing Docs](https://docs.cloud.google.com/load-balancing/docs/network/udp-with-network-load-balancing) |
| **Citus 12+ PostgreSQL Sharding** | Citus হ্যাশ-ডিস্ট্রিবিউটেড টেবিলের সব শার্ড একই ডিস্ট্রিবিউশন কলামে থাকলে (co-location) সরাসরি লোকাল নোডেই জয়েন ও ফরেন-কি কাজ করে; ভিন্ন কলামে শার্ড করলে ক্রস-নোড জয়েন ধীর হয়। | `init-citus-shards.sql`-এ `subscribers` (`subscriber_id`, ১২৮ শার্ড) এবং `radacct` (`tenant_id`, ২৫৬ শার্ড) আলাদা রয়েছে—তাই `radacct`-কে আলাদা অ্যানালিটিক্স/ClickHouse-এ নেওয়া হবে অথবা `subscriber_id`-তে কো-লোকেট করা হবে। | `VERIFIED` (2026-11-16) — [Citus Data Modeling Docs](https://docs.citusdata.com/en/stable/sharding/data_modeling.html), [Microsoft Citus Colocation](https://learn.microsoft.com/en-us/postgresql/citus/data-modeling?view=citus-14) |
| **FreeRADIUS CoA / PoD (RFC 5176)** | [RFC 3576](https://www.rfc-editor.org/info/rfc3576/)-কে আপডেট করে [RFC 5176](https://www.freeradius.org/rfc/rfc5176.html) স্ট্যান্ডার্ড অনুযায়ী CoA-Request ও Disconnect-Request উভয়ই **UDP পোর্ট 3799**-এ যায় (`CoA-ACK`/`Disconnect-ACK`)। | লাইভ স্পিড আপডেটে `CoA-Request` এবং মেয়াদোত্তীর্ণ গ্রাহককে `walled_garden_pool`-এ পাঠাতে `Disconnect-Request` (PoD) ব্যবহার হবে। | `VERIFIED` (2026-11-16) — [FreeRADIUS RFC 5176](https://www.freeradius.org/rfc/rfc5176.html), [RFC 3576](https://www.rfc-editor.org/info/rfc3576/) |
| **bKash Tokenized Checkout** | `v1.2.0-beta` API-তে `grant` টোকেন ১ ঘণ্টা ও `refresh` টোকেন ২৮ দিন মেয়াদি; ৩০ সেকেন্ড API টাইমআউট রাখতে হয় এবং Execute Payment রেসপন্সই চূড়ান্ত পেমেন্ট প্রমাণ। | প্রতিটি কলব্যাকের পর সার্ভার-সাইড Execute/Query দিয়ে যাচাই করে Redis `SETNX` দিয়ে ডুপ্লিকেট পেমেন্ট আটকানো হবে। | `VERIFIED` (2026-11-16) — [bKash Developer Docs](https://developer.bka.sh/docs/checkout-url-process-overview), [bKash Integration Guide](https://legacyscript.co/insights/bkash-payment-gateway-integration-guide-bangladesh), [bKash Sandbox API](https://arman.bd/bkash-sandbox-api/) |

---

## ২. ফিজিবিলিটি, ঝুঁকি ও আইসোলেটেড টেস্টিং (Feasibility & Risks)

| ঝুঁকি / চ্যালেঞ্জ | প্রভাব (Impact) | জিরো-রিস্ক মিটিগেশন (লাইভ নেটওয়ার্ক ছাড়াই) |
| :--- | :--- | :--- |
| **লাইভ নেটওয়ার্কে টেস্ট করলে গ্রাহক সংযোগ বিচ্ছিন্ন হওয়ার ঝুঁকি** | High | **নিষিদ্ধ:** কোনো কোড বা রাউটার কনফিগ লাইভ নেটওয়ার্কে টেস্ট হবে না। Docker + **Virtual MikroTik CHR** এবং `radtest`/`radclient` দিয়ে ১০০% সিমুলেটেড টেস্ট। |
| **Redis `allkeys-lru` পলিসি (`docker-compose.yml`)** | Critical | `allkeys-lru`-তে পেমেন্ট `SETNX` কী ডিলিট হতে পারে—তাই কোডিংয়ের আগেই `noeviction` সেট করা বাধ্যতামূলক। |
| **`clients.conf`-এ `0.0.0.0/0` ওপেন থাকা** | High (Security) | প্রোডাকশনে শুধুমাত্র WireGuard সাবনেট/BNG আইপিতে সীমাবদ্ধ করা ও GCP Secret Manager ব্যবহার। |
| **bKash স্যান্ডবক্স কলব্যাক ও টাইমআউট** | Medium | bKash Sandbox ওয়ালেট (`01823074817` ইত্যাদি) ও Mock Webhook Runner দিয়ে অটো-রিট্রাই ও `<3s` CoA টেস্ট। |

---

## ৩. Assumptions Register

| ID | অনুমান (Assumption) | স্ট্যাটাস | যাচাই পদ্ধতি (Verification Plan) |
| :--- | :--- | :--- | :--- |
| **ASM-01** | GCP `asia-south1` (Mumbai) থেকে বাংলাদেশের MikroTik BRAS-এ WireGuard RTT `< 45ms` থাকবে। | `ASSUMPTION` | GCE VM তৈরি করে MikroTik CHR / লোকাল পিসি থেকে `ping` ও `radtest` ল্যাটেন্সি মাপা। |
| **ASM-02** | ১M–১০M স্কেলে Citus ১২৮ শার্ড ও ClickHouse IPDR আলাদা রাখলে PostgreSQL-এ কোনো লক পড়বে না। | `CORROBORATED` | `init-citus-shards.sql` সম্পূর্ণ করে Docker-এ কানেকশন ও সিন্থেটিক লোড টেস্ট চালানো। |
| **ASM-03** | bKash/Nagad প্রোডাকশন মার্চেন্ট IP হোয়াইটলিস্ট ও HMAC সিক্রেট Go-Live-এর আগে পাওয়া যাবে। | `ASSUMPTION` | Stage 4-এ প্রথমে bKash Sandbox (`v1.2.0-beta`) দিয়ে এন্ড-টু-এন্ড ভেরিফিকেশন সম্পন্ন করা। |

---

## ৪. Gate 1 যাচাই (Next Step Readiness)
- [x] হাইব্রিড Cloud Run + GCE FreeRADIUS আর্কিটেকচার অফিশিয়াল ডক দিয়ে `VERIFIED`।
- [x] Citus শার্ডিং ও FreeRADIUS RFC 5176 UDP `3799` যাচাইকৃত।
- [x] bKash Tokenized Checkout API ফ্লো ও স্যান্ডবক্স টেস্টিং প্ল্যান প্রস্তুত।
- **পরবর্তী ধাপ (আপনার অনুমোদনে):** **Stage 2 (`PRD.md` ও `FR-xxx` Requirements + `RTM.csv`)** এবং `init-citus-shards.sql`-এর বাকি টেবিল (`radcheck`, `radreply`, `nas`, `tariffs`, `invoices`, `ont_devices`) তৈরি শুরু করা।
