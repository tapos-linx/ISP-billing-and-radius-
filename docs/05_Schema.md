# Database Schema: 10M Subscriber Scale

## Citus/PostgreSQL (128 Shards)
### Table: `subscribers`
- `id` (UUID, PK, Partition Key)
- `username` (VARCHAR, Unique)
- `password` (VARCHAR)
- `status` (ENUM: ACTIVE, EXPIRED, DISABLED)
- `shard_id` (INT, CRC32 % 128)
- `division` (ENUM: DHAKA, CHITTAGONG, ...)
- `ont_serial` (VARCHAR, Unique)
- `created_at` (TIMESTAMP)

### Table: `radcheck` / `radreply`
- Standard RADIUS attributes sharded alongside subscribers.

## ClickHouse (Accounting)
### Table: `radacct`
- `acct_session_id` (String)
- `username` (String)
- `nas_ip` (IPv4)
- `input_octets` (UInt64)
- `output_octets` (UInt64)
- `acct_start_time` (DateTime)
- `acct_stop_time` (DateTime)
- `public_ip` (IPv4)
- `port_block_start` (UInt16)

## Redis 7 (Fast-Path)
- `session:{username}` -> JSON Blob (IP, Speed, Expiry)
- `trx:{id}` -> Lock for payment idempotency
