-- P0-11: Distributed ICMP / SNMP Network Device Polling Schema

CREATE TABLE IF NOT EXISTS network_devices (
    device_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    name VARCHAR(64) NOT NULL,
    device_type VARCHAR(32) NOT NULL, -- 'router', 'switch', 'olt', 'server'
    ip_address VARCHAR(45) NOT NULL,
    snmp_community VARCHAR(64) DEFAULT 'public',
    snmp_version VARCHAR(8) DEFAULT '2c',
    poll_interval_sec INT DEFAULT 60,
    status VARCHAR(32) DEFAULT 'online', -- 'online', 'degraded', 'offline'
    last_polled_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS device_metrics (
    id BIGSERIAL PRIMARY KEY,
    device_id UUID NOT NULL,
    tenant_id INT NOT NULL,
    icmp_latency_ms NUMERIC(8,2),
    packet_loss_percent NUMERIC(5,2),
    cpu_utilization_percent NUMERIC(5,2),
    ram_utilization_percent NUMERIC(5,2),
    uptime_seconds BIGINT,
    polled_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_device_metrics_dev ON device_metrics (device_id, polled_at DESC);
