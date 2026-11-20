-- =============================================================================
-- PostgreSQL 16 / Citus 12.1: Double-Entry Ledger, Audit Logs & NBR/BTRC Reporting
-- Project: Carrier-Grade ISP Billing & FreeRADIUS AAA
-- =============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- 1. Chart of Accounts for ISP Double-Entry Bookkeeping
CREATE TABLE IF NOT EXISTS ledger_accounts (
    id SERIAL PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(128) NOT NULL,
    type VARCHAR(32) NOT NULL, -- asset, liability, equity, revenue, expense
    normal_balance VARCHAR(8) NOT NULL, -- debit, credit
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

-- Seed Standard ISP Chart of Accounts
INSERT INTO ledger_accounts (code, name, type, normal_balance) VALUES
    ('1010', 'Cash / MFS Clearing (bKash/Nagad)', 'asset', 'debit'),
    ('1100', 'Accounts Receivable (Subscribers)', 'asset', 'debit'),
    ('2200', 'NBR VAT Output Tax Payable (5% Mushak-6.3)', 'liability', 'credit'),
    ('4000', 'Broadband Internet Service Revenue', 'revenue', 'credit'),
    ('5100', 'Payment Gateway Transaction Expense', 'expense', 'debit')
ON CONFLICT (code) DO NOTHING;

-- 2. Payments Table
CREATE TABLE IF NOT EXISTS payments (
    payment_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    invoice_id UUID NOT NULL,
    subscriber_id UUID NOT NULL,
    tenant_id INT NOT NULL DEFAULT 1,
    amount_bdt NUMERIC(12, 2) NOT NULL,
    gateway VARCHAR(32) NOT NULL, -- bkash, nagad, bank, cash
    trx_id VARCHAR(128) NOT NULL UNIQUE,
    status VARCHAR(32) NOT NULL DEFAULT 'completed',
    paid_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_payments_invoice ON payments(invoice_id);
CREATE INDEX IF NOT EXISTS idx_payments_sub ON payments(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_payments_trx ON payments(trx_id);

-- 3. Double-Entry General Ledger Entries
CREATE TABLE IF NOT EXISTS ledger_entries (
    entry_id BIGSERIAL PRIMARY KEY,
    journal_entry_id UUID NOT NULL, -- Groups balanced debits & credits
    account_id INT NOT NULL REFERENCES ledger_accounts(id),
    invoice_id UUID,
    payment_id UUID,
    subscriber_id UUID,
    tenant_id INT NOT NULL DEFAULT 1,
    debit_bdt NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
    credit_bdt NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
    description VARCHAR(255) NOT NULL,
    posted_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_debit_or_credit CHECK (
        (debit_bdt > 0 AND credit_bdt = 0) OR
        (credit_bdt > 0 AND debit_bdt = 0)
    )
);
CREATE INDEX IF NOT EXISTS idx_ledger_journal ON ledger_entries(journal_entry_id);
CREATE INDEX IF NOT EXISTS idx_ledger_account ON ledger_entries(account_id);
CREATE INDEX IF NOT EXISTS idx_ledger_invoice ON ledger_entries(invoice_id);

-- 4. Immutable Append-Only Audit Log
CREATE TABLE IF NOT EXISTS billing_audit_logs (
    id BIGSERIAL PRIMARY KEY,
    event_id UUID NOT NULL DEFAULT gen_random_uuid(),
    event_type VARCHAR(64) NOT NULL, -- invoice_created, payment_received, invoice_settled, coa_pod_dispatched, coa_rate_restored
    entity_type VARCHAR(64) NOT NULL, -- invoice, payment, subscriber, coa_session
    entity_id VARCHAR(128) NOT NULL,
    actor_type VARCHAR(32) NOT NULL, -- system, mfs_webhook, admin, api
    actor_id VARCHAR(64),
    prev_state JSONB,
    new_state JSONB,
    ip_address VARCHAR(45),
    hash_signature VARCHAR(64) NOT NULL,
    prev_hash VARCHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_event_type ON billing_audit_logs(event_type);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON billing_audit_logs(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_created ON billing_audit_logs(created_at DESC);

-- Trigger to enforce strictly immutable, append-only logs (prevent UPDATE/DELETE)
CREATE OR REPLACE FUNCTION prevent_audit_log_modification()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'Security Policy Violation: billing_audit_logs is strictly append-only. Modification and deletion are prohibited.';
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_protect_billing_audit_logs ON billing_audit_logs;
CREATE TRIGGER trg_protect_billing_audit_logs
BEFORE UPDATE OR DELETE ON billing_audit_logs
FOR EACH ROW EXECUTE FUNCTION prevent_audit_log_modification();

-- 5. NBR VAT Mushak-6.3 Reporting Aggregations
CREATE TABLE IF NOT EXISTS vat_mushak_reports (
    report_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    year_month VARCHAR(7) NOT NULL UNIQUE, -- YYYY-MM
    total_invoices_count INT NOT NULL DEFAULT 0,
    gross_revenue_bdt NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
    taxable_subtotal_bdt NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
    vat_collected_5_percent_bdt NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
    exempt_amount_bdt NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
    mushak_version VARCHAR(16) NOT NULL DEFAULT 'Mushak-6.3',
    generated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 6. BTRC Regulatory Reporting Table
CREATE TABLE IF NOT EXISTS btrc_regulatory_reports (
    report_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    report_period VARCHAR(7) NOT NULL UNIQUE, -- YYYY-MM
    total_active_subscribers INT NOT NULL DEFAULT 0,
    total_expired_subscribers INT NOT NULL DEFAULT 0,
    cgnat_allocated_sessions INT NOT NULL DEFAULT 0,
    total_traffic_gb NUMERIC(14, 2) NOT NULL DEFAULT 0.00,
    compliance_status VARCHAR(32) NOT NULL DEFAULT 'COMPLIANT',
    btrc_tariff_tier VARCHAR(64) NOT NULL DEFAULT 'Nationwide ISP Tier-1',
    generated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
