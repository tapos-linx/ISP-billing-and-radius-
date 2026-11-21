-- P0-10: OLT / GPON Auto-Find & ONU Provisioning Schema

CREATE TABLE IF NOT EXISTS olts (
    olt_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    name VARCHAR(64) NOT NULL,
    vendor VARCHAR(32) NOT NULL, -- 'vsol', 'cdata', 'huawei', 'zte'
    ip_address VARCHAR(45) NOT NULL,
    snmp_community VARCHAR(64) DEFAULT 'public',
    management_port INT DEFAULT 22,
    auth_user VARCHAR(64),
    auth_secret VARCHAR(255),
    status VARCHAR(32) DEFAULT 'online',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS onus (
    onu_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    olt_id UUID NOT NULL,
    subscriber_id UUID,
    pon_port VARCHAR(32) NOT NULL, -- e.g. '0/1/1'
    onu_index INT,
    serial_number VARCHAR(64) NOT NULL, -- e.g. 'HWTC12345678'
    vlan_id INT NOT NULL,
    status VARCHAR(32) DEFAULT 'provisioned', -- 'discovered', 'provisioned', 'offline', 'terminated'
    rx_power_dbm NUMERIC(6,2),
    tx_power_dbm NUMERIC(6,2),
    distance_m INT,
    last_seen_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_onus_olt ON onus (olt_id);
CREATE INDEX IF NOT EXISTS idx_onus_serial ON onus (serial_number);
CREATE INDEX IF NOT EXISTS idx_onus_sub ON onus (subscriber_id);
