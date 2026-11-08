# PRD: ISP Billing System (5M–10M Carrier Edition)

## 1. Vision
Build a carrier-grade ISP Billing, AAA, and Network Management system capable of handling 5 to 10 million subscribers with high availability, low latency, and distributed sharding.

## 2. Key Features
- **NOC Command Center**: Real-time cluster telemetry and reconnect storm simulators.
- **Sharded Subscribers Directory**: 128-shard Citus distribution for massive scale.
- **Multi-Vendor VSA Support**: Compiler for MikroTik, Huawei, and Juniper.
- **TR-069 GenieACS Integration**: Zero-touch provisioning and optical telemetry.
- **MFS Webhook Billing**: Validated HMAC callbacks with <3s auto-unblock SLA.
- **BTRC CGNAT Compliance**: RFC 6888 deterministic port block mapping and inspector.
- **Hardware Deployment Exporter**: One-click generation of bare-metal configuration files.

## 3. Performance Targets
- **AAA Auth Latency**: <0.4ms (Redis Fast-Path).
- **Auto-Unblock SLA**: <3s from payment to CoA restoration.
- **Scale**: Up to 10,000,000 active sessions.
- **Sharding**: 128 horizontal shards using Citus/PostgreSQL.

## 4. Tech Stack
- **Frontend**: React 19, TypeScript, Tailwind CSS, Recharts, Lucide.
- **Backend (Simulated)**: React state-based engine mirroring distributed system behaviors.
- **Persistence**: Citus/PostgreSQL (128 shards), Redis 7, ClickHouse (Accounting).
- **Network**: FreeRADIUS 3.x, MikroTik RouterOS v7, Huawei NE8000, Juniper MX.
- **ACS**: GenieACS (TR-069).
