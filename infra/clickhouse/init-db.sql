-- =============================================================================
-- Release 2.0 (Epic 3): ClickHouse 2-Year BTRC IPDR & Accounting Telemetry Schema
-- High-throughput columnar telemetry engine with 730-day automated TTL
-- =============================================================================

CREATE DATABASE IF NOT EXISTS isp_telemetry;

USE isp_telemetry;

-- 1. BTRC IPDR Carrier Accounting & NAT Records (Mandatory 730-Day Retention)
CREATE TABLE IF NOT EXISTS btrc_ipdr_records (
    event_time DateTime CODEC(DoubleDelta, LZ4),
    username LowCardinality(String),
    tenant_id UInt32 DEFAULT 1,
    framed_ip IPv4 CODEC(LZ4),
    nas_ip IPv4 CODEC(LZ4),
    public_nat_ip IPv4 CODEC(LZ4),
    public_port_start UInt16,
    public_port_end UInt16,
    input_octets UInt64 CODEC(T64, LZ4),
    output_octets UInt64 CODEC(T64, LZ4),
    acct_session_id String CODEC(ZSTD),
    calling_station_id String CODEC(ZSTD),
    mac_address String CODEC(ZSTD),
    event_type LowCardinality(String) DEFAULT 'INTERIM' -- START, INTERIM, STOP, NAT_BIND
) ENGINE = MergeTree()
PARTITION BY toYYYYMM(event_time)
ORDER BY (framed_ip, event_time, username)
TTL event_time + INTERVAL 730 DAY;

-- 2. High-Velocity FreeRADIUS radacct Telemetry Table (100k+ writes/sec offload)
CREATE TABLE IF NOT EXISTS radacct_telemetry (
    radacctid UInt64,
    acctsessionid String CODEC(ZSTD),
    acctuniqueid String CODEC(ZSTD),
    username LowCardinality(String),
    tenant_id UInt32 DEFAULT 1,
    nasipaddress IPv4 CODEC(LZ4),
    nasportid String,
    acctstarttime DateTime CODEC(DoubleDelta, LZ4),
    acctupdatetime DateTime CODEC(DoubleDelta, LZ4),
    acctstoptime Nullable(DateTime) CODEC(DoubleDelta, LZ4),
    acctsessiontime UInt32,
    acctauthentic LowCardinality(String) DEFAULT 'RADIUS',
    connectinfo_start String,
    connectinfo_stop String,
    acctinputoctets UInt64 CODEC(T64, LZ4),
    acctoutputoctets UInt64 CODEC(T64, LZ4),
    calledstationid String,
    callingstationid String,
    acctterminatecause LowCardinality(String) DEFAULT '',
    framedipaddress IPv4 CODEC(LZ4),
    framedipv6prefix String DEFAULT ''
) ENGINE = MergeTree()
PARTITION BY toYYYYMM(acctstarttime)
ORDER BY (username, acctstarttime, framedipaddress)
TTL acctstarttime + INTERVAL 730 DAY;

-- 3. Materialized Monthly Regulatory Summary for Instant Audit Reports
CREATE TABLE IF NOT EXISTS btrc_monthly_summary (
    month_period Date,
    username LowCardinality(String),
    tenant_id UInt32,
    framed_ip IPv4,
    total_sessions UInt32,
    total_session_seconds UInt64,
    total_input_bytes UInt64,
    total_output_bytes UInt64,
    last_seen DateTime
) ENGINE = SummingMergeTree()
PARTITION BY toYYYYMM(month_period)
ORDER BY (month_period, tenant_id, username, framed_ip);
