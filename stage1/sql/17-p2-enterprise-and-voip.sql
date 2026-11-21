-- Phase 3 (P2 Batch 1): Enterprise SLA, Corporate Leased Lines, VoIP/SIP & Wholesale Carrier Schema

-- P2-1: Enterprise Circuits & SLA Monitoring
CREATE TABLE IF NOT EXISTS enterprise_circuits (
    circuit_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    subscriber_id UUID,
    circuit_ref VARCHAR(64) NOT NULL UNIQUE,
    committed_bandwidth_mbps INT NOT NULL,
    sla_target_availability_pct NUMERIC(5,2) NOT NULL DEFAULT 99.90,
    max_latency_ms INT NOT NULL DEFAULT 20,
    max_jitter_ms INT NOT NULL DEFAULT 5,
    max_packet_loss_pct NUMERIC(5,2) NOT NULL DEFAULT 0.10,
    monthly_rate_bdt NUMERIC(12,2) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_circuits_tenant ON enterprise_circuits (tenant_id, status);

CREATE TABLE IF NOT EXISTS sla_breaches (
    breach_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    circuit_id UUID NOT NULL REFERENCES enterprise_circuits(circuit_id) ON DELETE CASCADE,
    tenant_id INT NOT NULL,
    billing_month VARCHAR(7) NOT NULL, -- 'YYYY-MM'
    actual_availability_pct NUMERIC(5,2) NOT NULL,
    downtime_minutes INT NOT NULL,
    penalty_credit_bdt NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    credit_note_id INT,
    status VARCHAR(32) NOT NULL DEFAULT 'pending', -- 'pending', 'credited'
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_breaches_circuit ON sla_breaches (circuit_id, billing_month);

-- P2-5: Wholesale Carrier & L2TP LAC/LNS Tunnels
CREATE TABLE IF NOT EXISTS wholesale_carriers (
    carrier_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    carrier_name VARCHAR(128) NOT NULL,
    carrier_code VARCHAR(32) NOT NULL UNIQUE,
    account_number VARCHAR(64) NOT NULL,
    rate_per_mbps_bdt NUMERIC(10,2) NOT NULL DEFAULT 150.00,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS l2tp_tunnels (
    tunnel_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    carrier_id UUID NOT NULL REFERENCES wholesale_carriers(carrier_id) ON DELETE CASCADE,
    tenant_id INT NOT NULL,
    tunnel_name VARCHAR(64) NOT NULL,
    local_endpoint_ip VARCHAR(45) NOT NULL,
    remote_endpoint_ip VARCHAR(45) NOT NULL,
    shared_secret VARCHAR(64) NOT NULL,
    committed_mbps INT NOT NULL DEFAULT 1000,
    active_sessions INT NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'up',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_l2tp_carrier ON l2tp_tunnels (carrier_id, status);

-- P2-7: 95th Percentile Bandwidth Samples
CREATE TABLE IF NOT EXISTS bandwidth_samples_95th (
    sample_id BIGSERIAL PRIMARY KEY,
    circuit_id UUID NOT NULL REFERENCES enterprise_circuits(circuit_id) ON DELETE CASCADE,
    tenant_id INT NOT NULL,
    sampled_at TIMESTAMPTZ NOT NULL,
    in_mbps NUMERIC(10,2) NOT NULL,
    out_mbps NUMERIC(10,2) NOT NULL,
    peak_mbps NUMERIC(10,2) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_samples_circuit_time ON bandwidth_samples_95th (circuit_id, sampled_at);

-- P2-12: SIP Trunks, Dial-Plan Rates & VoIP CDRs
CREATE TABLE IF NOT EXISTS sip_trunks (
    trunk_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    name VARCHAR(64) NOT NULL,
    host VARCHAR(128) NOT NULL,
    port INT NOT NULL DEFAULT 5060,
    auth_username VARCHAR(64),
    auth_password VARCHAR(64),
    max_channels INT NOT NULL DEFAULT 30,
    status VARCHAR(32) NOT NULL DEFAULT 'registered',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS dial_plan_rates (
    rate_id BIGSERIAL PRIMARY KEY,
    tenant_id INT NOT NULL,
    destination_prefix VARCHAR(16) NOT NULL,
    destination_name VARCHAR(64) NOT NULL,
    rate_per_minute_bdt NUMERIC(10,4) NOT NULL,
    pulse_seconds INT NOT NULL DEFAULT 1,
    is_active BOOLEAN NOT NULL DEFAULT true
);

CREATE INDEX IF NOT EXISTS idx_dial_plan_prefix ON dial_plan_rates (tenant_id, destination_prefix);

-- Seed standard Bangladesh E.164 and international prefixes
INSERT INTO dial_plan_rates (tenant_id, destination_prefix, destination_name, rate_per_minute_bdt, pulse_seconds, is_active)
VALUES
    (1, '+880', 'Bangladesh Fixed Line', 0.4500, 1, true),
    (1, '+8801', 'Bangladesh Mobile AnyNet', 0.6000, 1, true),
    (1, '+1', 'USA / Canada Toll Free & Direct', 1.5000, 1, true),
    (1, '+44', 'United Kingdom Standard', 2.2000, 1, true)
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS voip_cdrs (
    cdr_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    trunk_id UUID REFERENCES sip_trunks(trunk_id) ON DELETE SET NULL,
    call_id VARCHAR(128) NOT NULL UNIQUE,
    caller_e164 VARCHAR(32) NOT NULL,
    callee_e164 VARCHAR(32) NOT NULL,
    started_at TIMESTAMPTZ NOT NULL,
    answered_at TIMESTAMPTZ,
    ended_at TIMESTAMPTZ NOT NULL,
    duration_seconds INT NOT NULL DEFAULT 0,
    billable_seconds INT NOT NULL DEFAULT 0,
    rate_per_minute_bdt NUMERIC(10,4) NOT NULL DEFAULT 0.0000,
    cost_bdt NUMERIC(10,4) NOT NULL DEFAULT 0.0000,
    disposition VARCHAR(32) NOT NULL DEFAULT 'ANSWERED',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cdrs_trunk ON voip_cdrs (trunk_id, started_at);
CREATE INDEX IF NOT EXISTS idx_cdrs_caller ON voip_cdrs (caller_e164);
