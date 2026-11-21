-- Phase 3 (P2 Batch 2): Network Intelligence, BGP/IXP, DNS Sinkhole, OTDR & AI Forecasting Schema

-- P2-2: BGP Peering & IXP Telemetry
CREATE TABLE IF NOT EXISTS bgp_peers (
    peer_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    peer_name VARCHAR(128) NOT NULL,
    peer_type VARCHAR(32) NOT NULL DEFAULT 'ixp', -- 'ixp', 'transit', 'cache', 'private'
    remote_asn BIGINT NOT NULL,
    remote_ip VARCHAR(45) NOT NULL,
    local_ip VARCHAR(45) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'established', -- 'established', 'idle', 'active', 'down'
    prefixes_received INT NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_bgp_peers_tenant ON bgp_peers (tenant_id, peer_type);

CREATE TABLE IF NOT EXISTS bgp_telemetry (
    telemetry_id BIGSERIAL PRIMARY KEY,
    peer_id UUID NOT NULL REFERENCES bgp_peers(peer_id) ON DELETE CASCADE,
    tenant_id INT NOT NULL,
    in_bps BIGINT NOT NULL DEFAULT 0,
    out_bps BIGINT NOT NULL DEFAULT 0,
    uptime_seconds INT NOT NULL DEFAULT 0,
    flaps_count INT NOT NULL DEFAULT 0,
    sampled_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_bgp_telemetry_peer ON bgp_telemetry (peer_id, sampled_at);

-- P2-3: DNS Sinkholing & Regulatory Content Filtering
CREATE TABLE IF NOT EXISTS dns_sinkhole_rules (
    rule_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    category VARCHAR(64) NOT NULL DEFAULT 'btrc_mandatory', -- 'btrc_mandatory', 'gambling', 'phishing', 'malware'
    sinkhole_ip VARCHAR(45) NOT NULL DEFAULT '10.254.254.254',
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sinkhole_domain ON dns_sinkhole_rules (domain, is_active);

CREATE TABLE IF NOT EXISTS dns_sinkhole_hits (
    hit_id BIGSERIAL PRIMARY KEY,
    rule_id UUID NOT NULL REFERENCES dns_sinkhole_rules(rule_id) ON DELETE CASCADE,
    tenant_id INT NOT NULL,
    client_ip VARCHAR(45) NOT NULL,
    queried_domain VARCHAR(255) NOT NULL,
    blocked_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sinkhole_hits_rule ON dns_sinkhole_hits (rule_id, blocked_at);

-- P2-4: OTDR Optical Time-Domain Reflectometer Distance Traces
CREATE TABLE IF NOT EXISTS otdr_traces (
    trace_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    circuit_or_port_ref VARCHAR(64) NOT NULL,
    optical_wavelength_nm INT NOT NULL DEFAULT 1550,
    fiber_refractive_index NUMERIC(6,4) NOT NULL DEFAULT 1.4682,
    pulse_width_ns INT NOT NULL DEFAULT 100,
    fault_distance_meters NUMERIC(10,2) NOT NULL,
    optical_loss_db NUMERIC(5,2) NOT NULL,
    fault_type VARCHAR(32) NOT NULL DEFAULT 'fiber_break', -- 'fiber_break', 'macrobend', 'high_loss_splice'
    estimated_location_address VARCHAR(255),
    traced_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_otdr_ref ON otdr_traces (circuit_or_port_ref, traced_at);

-- P2-8: Advanced Field Ops Mobile Offline Sync
CREATE TABLE IF NOT EXISTS mobile_sync_events (
    event_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    technician_id UUID NOT NULL REFERENCES field_technicians(technician_id) ON DELETE CASCADE,
    device_uuid VARCHAR(64) NOT NULL,
    event_type VARCHAR(64) NOT NULL, -- 'qr_scan_asset', 'splice_photo_upload', 'customer_sign_off'
    event_data JSONB NOT NULL,
    synced_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_mobile_sync_tech ON mobile_sync_events (technician_id, event_type);

-- P2-13: AI Capacity Forecasting
CREATE TABLE IF NOT EXISTS capacity_forecasts (
    forecast_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    resource_type VARCHAR(32) NOT NULL, -- 'pon_port', 'olt_uplink', 'cgnat_pool'
    resource_id VARCHAR(64) NOT NULL,
    current_utilization_pct NUMERIC(5,2) NOT NULL,
    daily_growth_rate_pct NUMERIC(5,2) NOT NULL,
    days_to_exhaustion INT NOT NULL,
    recommended_action VARCHAR(255) NOT NULL,
    forecasted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_capacity_res ON capacity_forecasts (resource_type, resource_id);
