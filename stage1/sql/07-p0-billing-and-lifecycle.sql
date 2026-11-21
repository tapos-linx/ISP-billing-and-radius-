-- =============================================================================
-- Phase 1 (P0): Production Blockers - Billing, Finance & Lifecycle Schema
-- Covers P0-4 (Pro-Rata), P0-5 (Credit Notes & Refunds), P0-6 (Dunning & Vacation)
-- =============================================================================

-- 1. Extend Subscribers Table for Dunning, Grace, Vacation & Pro-Rata
ALTER TABLE subscribers ADD COLUMN IF NOT EXISTS grace_until TIMESTAMPTZ;
ALTER TABLE subscribers ADD COLUMN IF NOT EXISTS vacation_until TIMESTAMPTZ;
ALTER TABLE subscribers ADD COLUMN IF NOT EXISTS auto_pay_enabled BOOLEAN DEFAULT FALSE;
ALTER TABLE subscribers ADD COLUMN IF NOT EXISTS pro_rata_balance NUMERIC(10, 2) DEFAULT 0.00;

-- 2. Credit Notes Table (P0-5)
CREATE TABLE IF NOT EXISTS credit_notes (
    id SERIAL PRIMARY KEY,
    credit_note_number VARCHAR(64) UNIQUE NOT NULL,
    tenant_id INT NOT NULL DEFAULT 1,
    invoice_id UUID NOT NULL,
    subscriber_id UUID NOT NULL,
    subtotal_bdt NUMERIC(10, 2) NOT NULL,
    vat_bdt NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    total_bdt NUMERIC(10, 2) NOT NULL,
    reason TEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'issued', -- draft, issued, applied, void
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_credit_notes_tenant ON credit_notes(tenant_id);
CREATE INDEX IF NOT EXISTS idx_credit_notes_subscriber ON credit_notes(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_credit_notes_invoice ON credit_notes(invoice_id);

-- 3. Refunds Table (P0-5)
CREATE TABLE IF NOT EXISTS refunds (
    id SERIAL PRIMARY KEY,
    refund_number VARCHAR(64) UNIQUE NOT NULL,
    tenant_id INT NOT NULL DEFAULT 1,
    payment_id UUID,
    invoice_id UUID NOT NULL,
    subscriber_id UUID NOT NULL,
    amount_bdt NUMERIC(10, 2) NOT NULL,
    method VARCHAR(32) NOT NULL, -- bkash, nagad, sslcommerz, stripe, cash
    gateway_refund_id VARCHAR(128),
    reason TEXT,
    status VARCHAR(32) NOT NULL DEFAULT 'completed', -- pending, completed, failed
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_refunds_tenant ON refunds(tenant_id);
CREATE INDEX IF NOT EXISTS idx_refunds_subscriber ON refunds(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_refunds_payment ON refunds(payment_id);

-- 4. Dunning Events Table (P0-6)
CREATE TABLE IF NOT EXISTS dunning_events (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL DEFAULT 1,
    subscriber_id UUID NOT NULL,
    invoice_id UUID NOT NULL,
    stage VARCHAR(32) NOT NULL, -- pre_reminder_3d, pre_reminder_1d, grace_active, late_fee_applied, suspended
    fee_assessed_bdt NUMERIC(10, 2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_dunning_events_subscriber ON dunning_events(subscriber_id);
CREATE INDEX IF NOT EXISTS idx_dunning_events_stage ON dunning_events(stage);
