# Incident Postmortem: [INC-YYYYMMDD-TITLE]

**Incident Date:** YYYY-MM-DD  
**Publish Date:** YYYY-MM-DD  
**Incident Commander:** [Name / Call-Sign]  
**Lead Investigator:** [Lead Architect / Senior Engineer]  
**Incident Severity:** [P1 - Critical / P2 - High]  
**Impact Duration:** XX minutes (Detection: XX min, Mitigation: XX min)  
**Carrier SLA Impact:** Availability dipped to XX.XX% (SLA Target: 99.999%)  
**Affected Subscribers:** ~XX,XXX PPPoE / Static IP sessions  

---

## 1. Executive Summary

A brief 2–3 paragraph summary explaining in plain language:
- What happened (e.g., Keepalived VIP flapping caused split-brain RADIUS queries).
- What was the customer impact (e.g., subscribers experienced delayed PPPoE authentication for 12 minutes).
- How the system was stabilized (e.g., Node 1 network interface was quarantined and traffic failed over to Node 2).
- What preventative steps are being taken to prevent recurrence.

---

## 2. Impact Quantification

| Dimension | Metric | SLA Threshold |
| :--- | :--- | :---: |
| **Duration of Service Degradation** | XX minutes | < 5 min |
| **Failed Authentication Attempts** | XX,XXX requests | 0 |
| **Delayed MFS Payments** | XX transactions | < 10 |
| **CoA Latency Spike** | XXXX ms | < 500 ms |
| **Revenue / Financial Impact** | BDT 0.00 | N/A |

---

## 3. Incident Chronological Timeline (BST / UTC+6)

*All timestamps verified against Prometheus, Loki, and syslog records.*

| Timestamp (BST) | Phase | Event Description | Action Taken |
| :---: | :--- | :--- | :--- |
| **14:02:11** | Trigger | Core switch uplink experienced physical fiber flap. | Automated failover triggered. |
| **14:03:05** | Detection | Prometheus alert `RadiusAuthLatencyHigh` fired. | NOC On-call paged. |
| **14:04:18** | Acknowledged | Incident commander joined emergency bridge. | Traffic inspection started. |
| **14:07:30** | Mitigation | Standby FreeRADIUS node promoted as primary VIP. | VIP stabilized on Node 2. |
| **14:11:45** | Recovery | PPPoE authentication rate returned to normal baseline. | Verification checks run. |
| **14:15:00** | Resolution | Incident closed. Monitoring in observation mode. | Postmortem scheduled. |

---

## 4. Root Cause Analysis (5-Whys)

1. **Why did PPPoE authentication stall?**  
   Because FreeRADIUS Node 1 stopped acknowledging `Access-Request` packets on UDP 1812.
2. **Why did Node 1 stop acknowledging requests?**  
   Because all 64 worker threads in the connection pool were blocked waiting for PostgreSQL responses.
3. **Why was PostgreSQL blocked?**  
   Because a lock contention occurred on table `radcheck` during an unindexed batch subscriber update.
4. **Why was an unindexed update executed during peak hours?**  
   Because a reseller sync cron script was scheduled at 14:00 without concurrency throttling.
5. **Why was the cron script able to monopolize database locks?**  
   Because database isolation levels and query statement timeouts (`statement_timeout = 5000`) were not strictly enforced for background batch sync jobs.

---

## 5. Lessons Learned

### What Went Well
- Prometheus alert fired within 54 seconds of the latency spike.
- Keepalived VIP switch successfully transferred traffic without restarting daemons.
- Redis fast-path kept existing online sessions active without mass disconnects.

### What Went Poorly
- The on-call engineer lacked an automated script to kill long-running batch lock queries immediately.
- Reseller batch sync was not sandboxed into read-only replica workers.

### Where We Got Lucky
- The event occurred during mid-afternoon off-peak hours, keeping affected subscribers below 2% of the fleet.
- The standby Citus worker was completely unaffected and absorbed the full traffic surge within 2 seconds.

---

## 6. Corrective and Preventative Actions (CAPA)

| ID | Action Item | Type | Owner | Target Date | Status |
| :--- | :--- | :---: | :--- | :---: | :---: |
| **CAPA-01** | Add `SET statement_timeout = '3s'` on all reseller batch queries | Prevention | Backend Lead | YYYY-MM-DD | Open |
| **CAPA-02** | Route reseller batch sync jobs exclusively to Citus worker read-replicas | Architecture| Data Architect | YYYY-MM-DD | Open |
| **CAPA-03** | Create automated `kill_blocking_queries.ps1` script in NOC runbook | Tooling | NOC Lead | YYYY-MM-DD | Open |
| **CAPA-04** | Add Prometheus alert for PostgreSQL lock waits exceeding 2 seconds | Monitoring | SRE Lead | YYYY-MM-DD | Open |

---

## 7. Sign-off & Approvals

- **Incident Commander:** ____________________ Date: _________
- **Lead Architect (Tapos):** ____________________ Date: _________
