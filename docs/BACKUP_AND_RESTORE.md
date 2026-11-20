# Automated Encrypted Backup & Disaster Recovery Runbook

**System:** Carrier-Grade ISP Billing & FreeRADIUS BSS/OSS  
**Target:** PostgreSQL 16 / Citus 12.1 Cluster & Redis 7 Fast-Path  
**Storage Target:** MinIO / AWS S3 (`s3://isp-encrypted-backups`)  
**Encryption Standard:** AES-256-CBC with PBKDF2 Key Derivation + SHA256 Checksum  

---

## 1. Architectural Overview

```mermaid
flowchart TD
    subgraph PrimaryCluster["Carrier Primary Stack"]
        PG["PostgreSQL Primary / Citus Coordinator"]
        Redis["Redis 7 Core Cache"]
        Cron["Backup Cron Runner"]
    end

    subgraph SecurityEngine["Encryption Engine"]
        Key["secrets/backup_key.txt (0600)"]
        OpenSSL["OpenSSL AES-256-CBC + PBKDF2"]
        SHA["SHA256 Hash Generator"]
    end

    subgraph ObjectStorage["Encrypted Object Storage"]
        MinIO["MinIO S3 Bucket (isp-encrypted-backups)"]
        AWS_S3["AWS S3 / Cloud Vault"]
    end

    subgraph RecoveryDrill["Disaster Recovery"]
        Verify["SHA256 Integrity Verification"]
        Decrypt["Decryption Pipeline"]
        Restore["psql Import & Citus Shard Sync"]
    end

    Cron -->|1. pg_dump & redis bgsave| PG
    Cron -->|2. dump.rdb| Redis
    PG & Redis --> OpenSSL
    Key --> OpenSSL
    OpenSSL -->|3. Encrypted Archive (.tar.gz.enc)| SHA
    SHA -->|4. Upload Archive + .sha256| MinIO
    MinIO -.->|Sync| AWS_S3

    MinIO -->|5. Fetch Backup| Verify
    Verify -->|6. Pass| Decrypt
    Key --> Decrypt
    Decrypt -->|7. Restore Tables & Partitions| Restore
```

---

## 2. Backup Execution

### Command-Line Execution
Run the automated backup script from the host or inside a scheduled task:

```bash
# Linux / WSL2
./scripts/backup_database_encrypted.sh

# Windows PowerShell
powershell -ExecutionPolicy Bypass -File scripts/backup_database_encrypted.ps1
```

### Script Execution Lifecycle
1. **PostgreSQL Dump**: Connects to `citus_master` or `postgres_primary` and executes `pg_dump --clean --if-exists`.
2. **Redis Snapshot**: Triggers `redis-cli bgsave` and copies `dump.rdb`.
3. **Archive & Encrypt**: Packages dumps into a compressed tarball and encrypts using `openssl enc -aes-256-cbc -salt -pbkdf2 -pass file:secrets/backup_key.txt`.
4. **Integrity Checksum**: Generates a cryptographically strong SHA256 checksum file (`.tar.gz.enc.sha256`).
5. **S3 / MinIO Upload**: Uploads both the encrypted archive and checksum to `s3://isp-encrypted-backups/` via AWS CLI or MinIO client.
6. **Retention Cleanup**: Automatically prunes local and remote archives older than `RETENTION_DAYS` (default: 30 days).

---

## 3. Disaster Recovery & Restoration Procedure

### Restoration Command
To restore from the latest encrypted backup:

```bash
# Linux / WSL2
./scripts/restore_database_encrypted.sh [path/to/backup.tar.gz.enc]

# Windows PowerShell
powershell -ExecutionPolicy Bypass -File scripts/restore_database_encrypted.ps1 -BackupFile "backups/backup_YYYYMMDD_HHMMSS.tar.gz.enc"
```

### Step-by-Step Manual Restoration Drill:
1. **Fetch Backup & Checksum**:
   ```bash
   mc cp myminio/isp-encrypted-backups/backup_20261120_194407.tar.gz.enc .
   mc cp myminio/isp-encrypted-backups/backup_20261120_194407.tar.gz.enc.sha256 .
   ```

2. **Verify SHA256 Checksum**:
   ```bash
   sha256sum -c backup_20261120_194407.tar.gz.enc.sha256
   # Must return: backup_20261120_194407.tar.gz.enc: OK
   ```

3. **Decrypt the Archive**:
   ```bash
   openssl enc -d -aes-256-cbc -pbkdf2 \
     -in backup_20261120_194407.tar.gz.enc \
     -out backup_decrypted.tar.gz \
     -pass file:secrets/backup_key.txt
   ```

4. **Extract and Restore**:
   ```bash
   tar -xzf backup_decrypted.tar.gz
   docker exec -i citus_master psql -U isp_admin -d isp_bss_oss < database_isp_bss_oss_*.sql
   ```

5. **Validate Database Consistency**:
   ```bash
   docker exec citus_master psql -U isp_admin -d isp_bss_oss -c "
     SELECT count(*) AS subscribers_count FROM subscribers;
     SELECT count(*) AS radcheck_count FROM radcheck;
     SELECT count(*) AS invoices_count FROM invoices;
   "
   ```

---

## 4. Automated Partition Maintenance (`radacct`)

The monthly accounting log table `radacct` is partitioned by range on `acctstarttime`.
Automated maintenance is handled via:

```bash
# Pre-create 6 months ahead and prune partitions older than 24 months
docker exec laravel_bss_oss php /opt/laravel_bss/artisan radacct:maintain-partitions --ahead=6 --retention=24
```

### Stored Procedure (`manage_radacct_monthly_partitions`):
- Creates future monthly partition tables (`radacct_yYYYYmMM`) automatically.
- Safely detaches expired historical partitions exceeding the regulatory retention window without locking active accounting ingestion.
