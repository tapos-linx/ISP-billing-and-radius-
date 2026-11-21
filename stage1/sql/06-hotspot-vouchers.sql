-- =============================================================================
-- Release 2.0 (Epic 1): Wi-Fi Hotspot Voucher Management System
-- Citus 12.1 + PostgreSQL 16 Distributed Schema for High-Speed Hotspots
-- =============================================================================

-- 1. Hotspot Profiles (Reference Table replicated across Citus workers)
CREATE TABLE IF NOT EXISTS hotspot_profiles (
    profile_id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL DEFAULT 1,
    name VARCHAR(64) NOT NULL,
    validity_seconds INT NOT NULL DEFAULT 3600,
    data_limit_bytes BIGINT NOT NULL DEFAULT 1073741824, -- 1 GB
    rate_limit_down_kbps INT NOT NULL DEFAULT 10240, -- 10 Mbps
    rate_limit_up_kbps INT NOT NULL DEFAULT 10240, -- 10 Mbps
    price_bdt NUMERIC(10, 2) NOT NULL DEFAULT 20.00,
    simultaneous_use INT NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- Distribute as Citus reference table if not already distributed
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_dist_partition WHERE logicalrelid = 'hotspot_profiles'::regclass) THEN
        PERFORM create_reference_table('hotspot_profiles');
    END IF;
END $$;

-- Seed Standard Default Profiles
INSERT INTO hotspot_profiles (profile_id, tenant_id, name, validity_seconds, data_limit_bytes, rate_limit_down_kbps, rate_limit_up_kbps, price_bdt)
VALUES
    (1, 1, '1-Hour Express Pass', 3600, 1073741824, 10240, 10240, 20.00),
    (2, 1, '1-Day Ultra Pass', 86400, 5368709120, 20480, 20480, 50.00),
    (3, 1, '7-Day Unlimited Pass', 604800, 0, 30720, 30720, 200.00)
ON CONFLICT (profile_id) DO UPDATE SET
    name = EXCLUDED.name,
    validity_seconds = EXCLUDED.validity_seconds,
    data_limit_bytes = EXCLUDED.data_limit_bytes,
    rate_limit_down_kbps = EXCLUDED.rate_limit_down_kbps,
    rate_limit_up_kbps = EXCLUDED.rate_limit_up_kbps,
    price_bdt = EXCLUDED.price_bdt;

SELECT setval('hotspot_profiles_profile_id_seq', (SELECT COALESCE(MAX(profile_id), 1) FROM hotspot_profiles));

-- 2. Hotspot Vouchers (Citus Distributed Table sharded on voucher_id)
CREATE TABLE IF NOT EXISTS hotspot_vouchers (
    voucher_id UUID NOT NULL DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL DEFAULT 1,
    profile_id INT NOT NULL,
    batch_id VARCHAR(32) NOT NULL,
    code VARCHAR(16) NOT NULL,
    pin VARCHAR(8),
    status VARCHAR(20) NOT NULL DEFAULT 'GENERATED', -- GENERATED, PURCHASED, ACTIVE, DEPLETED, EXPIRED
    time_limit_seconds INT NOT NULL DEFAULT 3600,
    data_limit_bytes BIGINT NOT NULL DEFAULT 1073741824,
    used_time_seconds INT NOT NULL DEFAULT 0,
    used_bytes BIGINT NOT NULL DEFAULT 0,
    rate_limit_down_kbps INT NOT NULL DEFAULT 10240,
    rate_limit_up_kbps INT NOT NULL DEFAULT 10240,
    price_bdt NUMERIC(10, 2) NOT NULL DEFAULT 20.00,
    mac_address VARCHAR(17),
    first_login_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (voucher_id, tenant_id)
);

-- Distribute as 128 Citus Shards
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_dist_partition WHERE logicalrelid = 'hotspot_vouchers'::regclass) THEN
        PERFORM create_distributed_table('hotspot_vouchers', 'voucher_id', shard_count := 128);
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_hotspot_vouchers_code ON hotspot_vouchers (code);
CREATE INDEX IF NOT EXISTS idx_hotspot_vouchers_batch ON hotspot_vouchers (batch_id);
CREATE INDEX IF NOT EXISTS idx_hotspot_vouchers_status ON hotspot_vouchers (status);
CREATE INDEX IF NOT EXISTS idx_hotspot_vouchers_tenant ON hotspot_vouchers (tenant_id);
