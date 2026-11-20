-- =============================================================================
-- PostgreSQL 16 + Citus 12.1 Complete Sharding & RADIUS Schema (Stage 4/5)
-- Upgrades uploaded init-citus-shards.sql with tariffs, nas, radcheck,
-- radreply, radgroupreply, invoices, payment_webhooks, and ont_devices.
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS citus;
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- -----------------------------------------------------------------------------
-- 1. Reference Tables (Replicated to All Citus Worker Nodes for Fast Joins)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tariffs (
    tariff_id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(64) NOT NULL,
    rate_limit VARCHAR(64) NOT NULL, -- e.g. '20M/20M'
    price_bdt NUMERIC(10, 2) NOT NULL,
    validity_days INT NOT NULL DEFAULT 30,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
SELECT create_reference_table('tariffs');

CREATE TABLE IF NOT EXISTS nas (
    id SERIAL PRIMARY KEY,
    nasname VARCHAR(128) NOT NULL UNIQUE,
    shortname VARCHAR(32),
    type VARCHAR(30) NOT NULL DEFAULT 'mikrotik',
    ports INT DEFAULT 1812,
    secret VARCHAR(128) NOT NULL,
    server VARCHAR(64),
    coa_port INT NOT NULL DEFAULT 3799,
    description VARCHAR(200)
);
SELECT create_reference_table('nas');

CREATE TABLE IF NOT EXISTS radgroupreply (
    id BIGSERIAL PRIMARY KEY,
    groupname VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op CHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL
);
SELECT create_reference_table('radgroupreply');

CREATE TABLE IF NOT EXISTS radusergroup (
    id SERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    priority INT NOT NULL DEFAULT 1
);
SELECT create_reference_table('radusergroup');

CREATE TABLE IF NOT EXISTS radgroupcheck (
    id SERIAL PRIMARY KEY,
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '==',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
SELECT create_reference_table('radgroupcheck');

CREATE TABLE IF NOT EXISTS radpostauth (
    id BIGSERIAL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    pass VARCHAR(64) NOT NULL,
    reply VARCHAR(32) NOT NULL,
    authdate TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SELECT create_reference_table('radpostauth');

-- -----------------------------------------------------------------------------
-- 2. Distributed Core Tables (128 Shards Co-located on subscriber_id)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscribers (
    subscriber_id UUID NOT NULL DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    tariff_id INT REFERENCES tariffs(tariff_id),
    username VARCHAR(64) NOT NULL,
    mac_address VARCHAR(17),
    vlan_id INT,
    bandwidth_profile VARCHAR(64),
    status VARCHAR(32) NOT NULL DEFAULT 'active', -- active | expired | suspended
    expires_at TIMESTAMPTZ NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '30 days'),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (subscriber_id, tenant_id)
);
SELECT create_distributed_table('subscribers', 'subscriber_id', shard_count := 128);
CREATE INDEX IF NOT EXISTS idx_subscribers_username ON subscribers (username);
CREATE INDEX IF NOT EXISTS idx_subscribers_status_exp ON subscribers (status, expires_at);

CREATE TABLE IF NOT EXISTS radcheck (
    id BIGSERIAL,
    subscriber_id UUID NOT NULL,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL DEFAULT 'Cleartext-Password',
    op CHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL,
    PRIMARY KEY (id, subscriber_id)
);
SELECT create_distributed_table('radcheck', 'subscriber_id', colocate_with := 'subscribers');
CREATE INDEX IF NOT EXISTS idx_radcheck_username ON radcheck (username);

CREATE TABLE IF NOT EXISTS radreply (
    id BIGSERIAL,
    subscriber_id UUID NOT NULL,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op CHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL,
    PRIMARY KEY (id, subscriber_id)
);
SELECT create_distributed_table('radreply', 'subscriber_id', colocate_with := 'subscribers');
CREATE INDEX IF NOT EXISTS idx_radreply_username ON radreply (username);

CREATE TABLE IF NOT EXISTS invoices (
    invoice_id UUID NOT NULL DEFAULT gen_random_uuid(),
    subscriber_id UUID NOT NULL,
    tenant_id INT NOT NULL,
    subtotal_bdt NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
    vat_rate_percent NUMERIC(5, 2) NOT NULL DEFAULT 5.00,
    vat_amount_bdt NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
    total_bdt NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
    amount_bdt NUMERIC(10, 2) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'unpaid', -- unpaid | paid | overdue
    isp_bin_number VARCHAR(32),
    mushak_6_3_number VARCHAR(64),
    customer_bin_nid VARCHAR(32),
    due_date TIMESTAMPTZ NOT NULL,
    paid_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (invoice_id, subscriber_id)
);
SELECT create_distributed_table('invoices', 'subscriber_id', colocate_with := 'subscribers');
CREATE INDEX IF NOT EXISTS idx_invoices_mushak ON invoices (mushak_6_3_number);

CREATE TABLE IF NOT EXISTS btrc_audit_logs (
    id BIGSERIAL,
    subscriber_id UUID NOT NULL,
    actor_type VARCHAR(32) NOT NULL,
    action VARCHAR(64) NOT NULL,
    framed_ip INET,
    metadata JSONB,
    retained_until TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, subscriber_id)
);
SELECT create_distributed_table('btrc_audit_logs', 'subscriber_id', colocate_with := 'subscribers');
CREATE INDEX IF NOT EXISTS idx_btrc_retention ON btrc_audit_logs (retained_until);

CREATE TABLE IF NOT EXISTS payment_webhooks (
    webhook_id UUID NOT NULL DEFAULT gen_random_uuid(),
    subscriber_id UUID NOT NULL,
    invoice_id UUID NOT NULL,
    gateway VARCHAR(32) NOT NULL, -- bkash | nagad | sslcommerz
    trx_id VARCHAR(128) NOT NULL,
    amount_bdt NUMERIC(10, 2) NOT NULL,
    hmac_verified BOOLEAN NOT NULL DEFAULT FALSE,
    processed_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (webhook_id, subscriber_id)
);
SELECT create_distributed_table('payment_webhooks', 'subscriber_id', colocate_with := 'subscribers');

CREATE TABLE IF NOT EXISTS failed_coa_jobs (
    id BIGSERIAL PRIMARY KEY,
    subscriber_id UUID NOT NULL,
    username VARCHAR(128) NOT NULL,
    nas_ip VARCHAR(64) NOT NULL,
    action VARCHAR(32) NOT NULL,
    error_reason TEXT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ont_devices (
    ont_id UUID NOT NULL DEFAULT gen_random_uuid(),
    subscriber_id UUID NOT NULL,
    serial_number VARCHAR(64) NOT NULL,
    olt_pon_port VARCHAR(64),
    rx_power_dbm NUMERIC(5, 2),
    last_seen_at TIMESTAMPTZ,
    PRIMARY KEY (ont_id, subscriber_id)
);
SELECT create_distributed_table('ont_devices', 'subscriber_id', colocate_with := 'subscribers');

-- -----------------------------------------------------------------------------
-- 3. Distributed RADIUS Accounting Table (256 Shards)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS radacct (
    radacctid BIGSERIAL,
    acctsessionid VARCHAR(64) NOT NULL,
    username VARCHAR(64) NOT NULL,
    realm VARCHAR(64),
    nasipaddress VARCHAR(45) NOT NULL,
    acctstarttime TIMESTAMPTZ,
    acctstoptime TIMESTAMPTZ,
    acctinputoctets BIGINT,
    acctoutputoctets BIGINT,
    calledstationid VARCHAR(50),
    callingstationid VARCHAR(50),
    terminatecause VARCHAR(32),
    tenant_id INT NOT NULL,
    PRIMARY KEY (radacctid, tenant_id)
);
SELECT create_distributed_table('radacct', 'tenant_id', shard_count := 256);
CREATE INDEX IF NOT EXISTS idx_radacct_user_start ON radacct (username, acctstarttime DESC);
