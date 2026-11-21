-- Phase 2 (P1 Batch 1): AAA, Traffic & Bandwidth Engineering Schema
-- FUP Quotas, Time-of-Day Profiles, NetFlow Telemetry & CGNAT IPDR Bindings

CREATE TABLE IF NOT EXISTS fup_quotas (
    quota_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    subscriber_id UUID NOT NULL,
    cycle_start TIMESTAMPTZ NOT NULL,
    cycle_end TIMESTAMPTZ NOT NULL,
    data_limit_bytes BIGINT NOT NULL,
    used_bytes BIGINT NOT NULL DEFAULT 0,
    rollover_bytes BIGINT NOT NULL DEFAULT 0,
    throttle_rate_limit VARCHAR(32) NOT NULL DEFAULT '2M/2M',
    is_throttled BOOLEAN NOT NULL DEFAULT false,
    throttled_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_fup_sub ON fup_quotas (subscriber_id, tenant_id);
CREATE INDEX IF NOT EXISTS idx_fup_status ON fup_quotas (is_throttled);

CREATE TABLE IF NOT EXISTS data_topups (
    topup_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    subscriber_id UUID NOT NULL,
    data_bytes BIGINT NOT NULL,
    price_bdt NUMERIC(10,2) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'applied',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_topups_sub ON data_topups (subscriber_id);

CREATE TABLE IF NOT EXISTS time_of_day_profiles (
    profile_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(64) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    boost_factor NUMERIC(4,2) NOT NULL DEFAULT 2.00,
    boost_rate_limit VARCHAR(32) NOT NULL,
    contention_ratio VARCHAR(16) NOT NULL DEFAULT '1:4',
    days_of_week VARCHAR(32) NOT NULL DEFAULT 'daily',
    is_active BOOLEAN NOT NULL DEFAULT true
);

INSERT INTO time_of_day_profiles (tenant_id, name, start_time, end_time, boost_factor, boost_rate_limit, contention_ratio, days_of_week)
VALUES
    (1, 'Night Owl Speed Boost', '00:00:00', '08:00:00', 2.00, '40M/40M', '1:2', 'daily'),
    (1, 'Weekend Ultra Stream', '08:00:00', '23:59:59', 1.50, '30M/30M', '1:4', 'weekends')
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS netflow_records (
    flow_id BIGSERIAL PRIMARY KEY,
    tenant_id INT NOT NULL,
    subscriber_id UUID,
    src_ip VARCHAR(45) NOT NULL,
    dst_ip VARCHAR(45) NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    src_port INT NOT NULL,
    dst_port INT NOT NULL,
    bytes BIGINT NOT NULL,
    packets BIGINT NOT NULL,
    category VARCHAR(32) NOT NULL DEFAULT 'general',
    captured_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_netflow_sub ON netflow_records (subscriber_id, captured_at DESC);
CREATE INDEX IF NOT EXISTS idx_netflow_category ON netflow_records (category);

CREATE TABLE IF NOT EXISTS cgnat_bindings (
    binding_id BIGSERIAL PRIMARY KEY,
    tenant_id INT NOT NULL,
    subscriber_id UUID NOT NULL,
    private_ip VARCHAR(45) NOT NULL,
    public_ip VARCHAR(45) NOT NULL,
    port_start INT NOT NULL,
    port_end INT NOT NULL,
    allocated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at TIMESTAMPTZ,
    retained_until TIMESTAMPTZ NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '730 days')
);

CREATE INDEX IF NOT EXISTS idx_cgnat_lookup ON cgnat_bindings (public_ip, port_start, port_end, allocated_at, released_at);
CREATE INDEX IF NOT EXISTS idx_cgnat_sub ON cgnat_bindings (subscriber_id);
