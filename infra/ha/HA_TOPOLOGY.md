# On-Prem / VPS Docker High-Availability Topology

1. **PostgreSQL / Citus Streaming Replication + pgBackRest:**
   - Primary (`citus_master`) streams WAL to hot standby replica (`wal_level=replica`, `max_wal_senders=10`).
   - Run `infra/ha/pgbackrest_backup_restore_drill.sh` daily to verify `radacct`, `radcheck`, and `radreply` point-in-time recovery.
2. **Dual FreeRADIUS + Keepalived VIP (`10.10.0.10`):**
   - Deploy `freeradius_primary` and `freeradius_secondary` on separate hosts.
   - MikroTik BNGs point RADIUS Auth (`1812/udp`), Acct (`1813/udp`), and CoA (`3799/udp`) to VIP `10.10.0.10` managed by `infra/ha/keepalived.conf`.
3. **Redis Sentinel (`26379/tcp`):**
   - 1 Primary + 2 Replicas + 3 Sentinel instances (`infra/ha/sentinel.conf`), keeping `maxmemory-policy noeviction` so MFS `SETNX` locks are never dropped.
