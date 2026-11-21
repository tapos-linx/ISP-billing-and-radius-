-- 19-p2-ha-banking-audit.sql
-- Phase 3 Batch 3: High Availability, Banking Clearing (BEFTN/NPSB), Anycast Topology & Disaster Recovery

CREATE TABLE IF NOT EXISTS beftn_batches (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL DEFAULT 1,
    batch_number VARCHAR(64) NOT NULL UNIQUE,
    settlement_date DATE NOT NULL,
    clearing_type VARCHAR(20) NOT NULL DEFAULT 'BEFTN',
    total_count INT NOT NULL DEFAULT 0,
    total_amount_bdt NUMERIC(14,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    originator_bank_code VARCHAR(20) NOT NULL,
    originator_routing_no VARCHAR(20) NOT NULL,
    originator_account_no VARCHAR(50) NOT NULL,
    originator_name VARCHAR(150) NOT NULL,
    file_reference VARCHAR(100) NULL,
    batch_payload TEXT NULL,
    submitted_at TIMESTAMP NULL,
    settled_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_beftn_batches_tenant ON beftn_batches(tenant_id);
CREATE INDEX IF NOT EXISTS idx_beftn_batches_status ON beftn_batches(status);
CREATE INDEX IF NOT EXISTS idx_beftn_batches_date ON beftn_batches(settlement_date);

CREATE TABLE IF NOT EXISTS beftn_transactions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL DEFAULT 1,
    batch_id UUID NOT NULL REFERENCES beftn_batches(id) ON DELETE CASCADE,
    invoice_id UUID NOT NULL,
    subscriber_id UUID NOT NULL,
    instruction_id VARCHAR(64) NOT NULL UNIQUE,
    mandate_reference VARCHAR(64) NOT NULL,
    customer_bank_name VARCHAR(100) NOT NULL,
    customer_routing_no VARCHAR(20) NOT NULL,
    customer_account_no VARCHAR(50) NOT NULL,
    account_title VARCHAR(150) NOT NULL,
    amount_bdt NUMERIC(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    return_code VARCHAR(20) NULL,
    failure_reason VARCHAR(255) NULL,
    settled_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_beftn_tx_tenant ON beftn_transactions(tenant_id);
CREATE INDEX IF NOT EXISTS idx_beftn_tx_batch ON beftn_transactions(batch_id);
CREATE INDEX IF NOT EXISTS idx_beftn_tx_invoice ON beftn_transactions(invoice_id);
CREATE INDEX IF NOT EXISTS idx_beftn_tx_subscriber ON beftn_transactions(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_beftn_tx_status ON beftn_transactions(status);

CREATE TABLE IF NOT EXISTS anycast_nodes (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL DEFAULT 1,
    node_code VARCHAR(32) NOT NULL UNIQUE,
    region VARCHAR(64) NOT NULL,
    pop_name VARCHAR(100) NOT NULL,
    vip_ipv4 INET NOT NULL,
    physical_ip INET NOT NULL,
    bgp_asn INT NOT NULL DEFAULT 65001,
    bgp_community VARCHAR(32) NOT NULL DEFAULT '65001:100',
    weight INT NOT NULL DEFAULT 100,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    is_announced BOOLEAN NOT NULL DEFAULT true,
    auth_latency_ms NUMERIC(8,2) DEFAULT 0.00,
    replication_lag_seconds NUMERIC(8,2) DEFAULT 0.00,
    last_heartbeat_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_anycast_nodes_tenant ON anycast_nodes(tenant_id);
CREATE INDEX IF NOT EXISTS idx_anycast_nodes_region ON anycast_nodes(region);
CREATE INDEX IF NOT EXISTS idx_anycast_nodes_status ON anycast_nodes(status);

CREATE TABLE IF NOT EXISTS anycast_health_checks (
    id BIGSERIAL PRIMARY KEY,
    node_id UUID NOT NULL REFERENCES anycast_nodes(id) ON DELETE CASCADE,
    auth_latency_ms NUMERIC(8,2) NOT NULL,
    acct_latency_ms NUMERIC(8,2) NOT NULL,
    replication_lag_seconds NUMERIC(8,2) NOT NULL,
    bgp_session_up BOOLEAN NOT NULL DEFAULT true,
    packet_loss_pct NUMERIC(5,2) NOT NULL DEFAULT 0.00,
    is_healthy BOOLEAN NOT NULL DEFAULT true,
    details JSONB NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_anycast_hc_node ON anycast_health_checks(node_id);
CREATE INDEX IF NOT EXISTS idx_anycast_hc_time ON anycast_health_checks(checked_at);

CREATE TABLE IF NOT EXISTS dr_failover_drills (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL DEFAULT 1,
    drill_name VARCHAR(100) NOT NULL,
    drill_type VARCHAR(50) NOT NULL,
    initiated_by VARCHAR(100) NOT NULL,
    primary_region VARCHAR(50) NOT NULL DEFAULT 'dc1-dhaka',
    secondary_region VARCHAR(50) NOT NULL DEFAULT 'dc2-chattogram',
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    rto_seconds NUMERIC(8,2) NOT NULL DEFAULT 0.00,
    rpo_seconds NUMERIC(8,2) NOT NULL DEFAULT 0.00,
    sentinel_failover_verified BOOLEAN NOT NULL DEFAULT false,
    replica_promotion_verified BOOLEAN NOT NULL DEFAULT false,
    s3_wal_restored BOOLEAN NOT NULL DEFAULT false,
    anycast_diverted BOOLEAN NOT NULL DEFAULT false,
    audit_hash VARCHAR(64) NULL,
    drill_log JSONB NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_dr_drills_tenant ON dr_failover_drills(tenant_id);
CREATE INDEX IF NOT EXISTS idx_dr_drills_type ON dr_failover_drills(drill_type);
