# TRD: ISP Billing System (Carrier Edition)

## 1. Sharding Architecture
- **Distributor**: Citus Data extension for PostgreSQL.
- **Shard Count**: 128 (Support for 10M subscribers).
- **Partition Key**: `subscriber_id`.
- **Replication**: 2x availability per shard.

## 2. AAA Performance
- **Redis 7**: Cache-aside pattern for active subscriber sessions.
- **Fast-Path**: FreeRADIUS `rlm_redis` module for sub-millisecond lookups.
- **ClickHouse**: Dedicated OLAP store for RADIUS accounting (radacct) to handle 100k writes/sec.

## 3. CWMP / TR-069
- **GenieACS**: Integrated via NBI (Northbound Interface) using JSON/REST.
- **Auto-Provisioning**: Scripted presets for Huawei, ZTE, and VSOL.

## 4. Security
- **HMAC SHA256**: For payment gateway callbacks.
- **RBAC**: Role-based access for NOC engineers vs. Billing admins.
- **RFC 3576**: Secure UDP 3799 packet handling with Message-Authenticator.
