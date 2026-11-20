-- ==============================================================================
-- Phase 6: Multi-tenancy, RBAC & Reseller Data Isolation Schema
-- Adds tenants, ip_pools, role-based user hierarchies, and tenant constraints.
-- ==============================================================================

-- 1. Tenants Table
CREATE TABLE IF NOT EXISTS tenants (
    id SERIAL PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    slug VARCHAR(64) NOT NULL UNIQUE,
    type VARCHAR(32) NOT NULL DEFAULT 'reseller', -- carrier, franchise, reseller
    status VARCHAR(32) NOT NULL DEFAULT 'active', -- active, suspended
    ip_pool_cidr VARCHAR(64),
    bandwidth_quota_mbps INTEGER DEFAULT 1000,
    contact_email VARCHAR(128),
    contact_phone VARCHAR(32),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tenants_slug ON tenants(slug);
CREATE INDEX IF NOT EXISTS idx_tenants_status ON tenants(status);

-- Seed Default Tenants
INSERT INTO tenants (id, name, slug, type, status, ip_pool_cidr, bandwidth_quota_mbps, contact_email)
VALUES
    (1, 'Primary Carrier Headquarters', 'carrier-hq', 'carrier', 'active', '100.64.0.0/16', 100000, 'noc@carrier.net'),
    (2, 'Uttara North Franchise & Reseller', 'reseller-uttara', 'reseller', 'active', '100.64.10.0/24', 5000, 'billing@uttara-reseller.net'),
    (3, 'Dhanmondi South Franchise & Reseller', 'reseller-dhanmondi', 'reseller', 'active', '100.64.20.0/24', 5000, 'billing@dhanmondi-reseller.net')
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    slug = EXCLUDED.slug,
    type = EXCLUDED.type,
    ip_pool_cidr = EXCLUDED.ip_pool_cidr;

-- Synchronize sequence
SELECT setval('tenants_id_seq', (SELECT MAX(id) FROM tenants));

-- 2. IP Pools Table (Assigned per Tenant)
CREATE TABLE IF NOT EXISTS ip_pools (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    pool_name VARCHAR(64) NOT NULL,
    subnet_cidr VARCHAR(64) NOT NULL,
    gateway_ip VARCHAR(45) NOT NULL,
    range_start VARCHAR(45) NOT NULL,
    range_end VARCHAR(45) NOT NULL,
    total_ips INTEGER NOT NULL DEFAULT 254,
    allocated_ips INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_tenant_pool UNIQUE (tenant_id, pool_name)
);

CREATE INDEX IF NOT EXISTS idx_ip_pools_tenant ON ip_pools(tenant_id);

-- Seed IP Pools
INSERT INTO ip_pools (tenant_id, pool_name, subnet_cidr, gateway_ip, range_start, range_end, total_ips, allocated_ips)
VALUES
    (1, 'carrier_core_pool', '100.64.0.0/16', '100.64.0.1', '100.64.0.2', '100.64.255.254', 65534, 12),
    (2, 'uttara_north_pool', '100.64.10.0/24', '100.64.10.1', '100.64.10.2', '100.64.10.254', 253, 5),
    (3, 'dhanmondi_south_pool', '100.64.20.0/24', '100.64.20.1', '100.64.20.2', '100.64.20.254', 253, 3)
ON CONFLICT (tenant_id, pool_name) DO UPDATE SET
    subnet_cidr = EXCLUDED.subnet_cidr,
    gateway_ip = EXCLUDED.gateway_ip;

-- 3. Enhance Users Table with Tenant and RBAC Fields
ALTER TABLE users ADD COLUMN IF NOT EXISTS tenant_id INTEGER NOT NULL DEFAULT 1 REFERENCES tenants(id);
ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(32) NOT NULL DEFAULT 'subscriber';
ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT 'active';
ALTER TABLE users ADD COLUMN IF NOT EXISTS api_token VARCHAR(128);

CREATE INDEX IF NOT EXISTS idx_users_tenant_role ON users(tenant_id, role);
CREATE INDEX IF NOT EXISTS idx_users_api_token ON users(api_token);

-- Seed RBAC Test & Operator Users
-- Roles: SuperAdmin, TenantAdmin, NocEngineer, Reseller, BillingClerk
INSERT INTO users (name, email, password, tenant_id, role, status, api_token)
VALUES
    ('Carrier Super Administrator', 'superadmin@carrier.isp', '$2y$12$eA8b/nK9kOQ2f2V/7t1g1u1v3y8X9w0Z1a2b3c4d5e6f7g8h9i0j.', 1, 'SuperAdmin', 'active', 'token_superadmin_carrier_2026'),
    ('Carrier NOC Engineer', 'noc@carrier.isp', '$2y$12$eA8b/nK9kOQ2f2V/7t1g1u1v3y8X9w0Z1a2b3c4d5e6f7g8h9i0j.', 1, 'NocEngineer', 'active', 'token_noc_carrier_2026'),
    ('Carrier Billing Officer', 'billing@carrier.isp', '$2y$12$eA8b/nK9kOQ2f2V/7t1g1u1v3y8X9w0Z1a2b3c4d5e6f7g8h9i0j.', 1, 'BillingClerk', 'active', 'token_billing_carrier_2026'),
    ('Uttara Tenant Administrator', 'admin@uttara.isp', '$2y$12$eA8b/nK9kOQ2f2V/7t1g1u1v3y8X9w0Z1a2b3c4d5e6f7g8h9i0j.', 2, 'TenantAdmin', 'active', 'token_admin_uttara_2026'),
    ('Uttara Reseller Agent', 'reseller@uttara.isp', '$2y$12$eA8b/nK9kOQ2f2V/7t1g1u1v3y8X9w0Z1a2b3c4d5e6f7g8h9i0j.', 2, 'Reseller', 'active', 'token_reseller_uttara_2026'),
    ('Dhanmondi Reseller Agent', 'reseller@dhanmondi.isp', '$2y$12$eA8b/nK9kOQ2f2V/7t1g1u1v3y8X9w0Z1a2b3c4d5e6f7g8h9i0j.', 3, 'Reseller', 'active', 'token_reseller_dhanmondi_2026')
ON CONFLICT (email) DO UPDATE SET
    tenant_id = EXCLUDED.tenant_id,
    role = EXCLUDED.role,
    api_token = EXCLUDED.api_token;

-- 4. Enhance NAS Router Table with Tenant Binding
ALTER TABLE nas ADD COLUMN IF NOT EXISTS tenant_id INTEGER NOT NULL DEFAULT 1 REFERENCES tenants(id);
CREATE INDEX IF NOT EXISTS idx_nas_tenant ON nas(tenant_id);
