-- Phase 2 (P1 Batch 3): Commercial Onboarding, CRM & Reseller Hierarchy Schema

-- P1-11: Leads, Feasibility & Quotes
CREATE TABLE IF NOT EXISTS leads (
    lead_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    name VARCHAR(128) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    email VARCHAR(128),
    address VARCHAR(255) NOT NULL,
    lat NUMERIC(10,7),
    lng NUMERIC(10,7),
    status VARCHAR(32) NOT NULL DEFAULT 'new', -- 'new', 'feasibility_checked', 'quoted', 'converted', 'lost'
    stage VARCHAR(32) NOT NULL DEFAULT 'inquiry',
    notes TEXT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_leads_tenant ON leads (tenant_id, status);

CREATE TABLE IF NOT EXISTS feasibility_checks (
    feasibility_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    lead_id UUID NOT NULL REFERENCES leads(lead_id) ON DELETE CASCADE,
    nearest_pop_or_fat VARCHAR(128) NOT NULL,
    distance_meters INT NOT NULL,
    is_feasible BOOLEAN NOT NULL DEFAULT true,
    max_bandwidth_mbps INT NOT NULL DEFAULT 100,
    estimated_drop_fiber_meters INT NOT NULL DEFAULT 50,
    checked_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_feasibility_lead ON feasibility_checks (lead_id);

CREATE TABLE IF NOT EXISTS quotes (
    quote_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    lead_id UUID NOT NULL REFERENCES leads(lead_id) ON DELETE CASCADE,
    tariff_id INT,
    installation_charge_bdt NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    monthly_recurring_bdt NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(32) NOT NULL DEFAULT 'draft', -- 'draft', 'sent', 'accepted', 'declined'
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_quotes_lead ON quotes (lead_id);

-- P1-12: Digital eKYC / eCAF Submissions
CREATE TABLE IF NOT EXISTS ecaf_submissions (
    ecaf_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    subscriber_id UUID,
    nid_number VARCHAR(32) NOT NULL,
    dob DATE NOT NULL,
    full_name_bangla VARCHAR(128),
    full_name_english VARCHAR(128) NOT NULL,
    father_name VARCHAR(128),
    mother_name VARCHAR(128),
    permanent_address TEXT,
    present_address TEXT NOT NULL,
    nid_front_path VARCHAR(255),
    nid_back_path VARCHAR(255),
    live_selfie_path VARCHAR(255),
    customer_signature_path VARCHAR(255),
    verification_status VARCHAR(32) NOT NULL DEFAULT 'pending', -- 'pending', 'verified', 'rejected'
    btrc_verified_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ecaf_nid ON ecaf_submissions (nid_number);
CREATE INDEX IF NOT EXISTS idx_ecaf_sub ON ecaf_submissions (subscriber_id);

-- P1-15: Multi-Tier Reseller Wallets & Transactions
CREATE TABLE IF NOT EXISTS reseller_wallets (
    wallet_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    reseller_id INT NOT NULL UNIQUE,
    balance_bdt NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    credit_limit_bdt NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    commission_rate_pct NUMERIC(5,2) NOT NULL DEFAULT 15.00,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reseller_transactions (
    transaction_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    wallet_id UUID NOT NULL REFERENCES reseller_wallets(wallet_id) ON DELETE CASCADE,
    tenant_id INT NOT NULL,
    type VARCHAR(32) NOT NULL, -- 'topup', 'activation_debit', 'commission_credit', 'refund'
    amount_bdt NUMERIC(12,2) NOT NULL,
    balance_after_bdt NUMERIC(12,2) NOT NULL,
    reference_id VARCHAR(64),
    description VARCHAR(255),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_reseller_tx_wallet ON reseller_transactions (wallet_id, created_at);

-- P1-16: Promotions & Discount Coupons
CREATE TABLE IF NOT EXISTS promotions (
    promo_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    code VARCHAR(32) NOT NULL UNIQUE,
    discount_type VARCHAR(16) NOT NULL DEFAULT 'percentage', -- 'percentage', 'fixed'
    discount_value NUMERIC(10,2) NOT NULL,
    min_plan_amount_bdt NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    valid_from TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    valid_until TIMESTAMPTZ NOT NULL,
    usage_limit INT NOT NULL DEFAULT 100,
    used_count INT NOT NULL DEFAULT 0,
    active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_promo_code ON promotions (code, active);
