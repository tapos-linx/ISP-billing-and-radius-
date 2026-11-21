-- Phase 2 (P1 Batch 4): FinOps, Cashdesk, Webhooks & Executive BI Schema

-- P1-4: Bank Statement Reconciliation
CREATE TABLE IF NOT EXISTS bank_statements (
    statement_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    bank_name VARCHAR(64) NOT NULL,
    account_number VARCHAR(64) NOT NULL,
    statement_date DATE NOT NULL,
    total_credits_bdt NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    total_debits_bdt NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(32) NOT NULL DEFAULT 'uploaded', -- 'uploaded', 'reconciled'
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bank_statement_lines (
    line_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    statement_id UUID NOT NULL REFERENCES bank_statements(statement_id) ON DELETE CASCADE,
    tenant_id INT NOT NULL,
    transaction_date DATE NOT NULL,
    reference_number VARCHAR(128) NOT NULL,
    description VARCHAR(255),
    amount_bdt NUMERIC(12,2) NOT NULL,
    matched_invoice_id UUID,
    match_status VARCHAR(32) NOT NULL DEFAULT 'pending', -- 'pending', 'matched', 'reconciled', 'unmatched'
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_bank_lines_ref ON bank_statement_lines (reference_number);
CREATE INDEX IF NOT EXISTS idx_bank_lines_status ON bank_statement_lines (match_status);

-- P1-5: Cashier POS & Cash Drawer Shifts
CREATE TABLE IF NOT EXISTS cash_shifts (
    shift_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    cashier_user_id INT NOT NULL,
    opened_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMPTZ,
    starting_cash_bdt NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    collected_cash_bdt NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    closing_cash_bdt NUMERIC(10,2),
    discrepancy_bdt NUMERIC(10,2) DEFAULT 0.00,
    status VARCHAR(32) NOT NULL DEFAULT 'open', -- 'open', 'closed'
    notes TEXT
);

CREATE TABLE IF NOT EXISTS cash_collections (
    collection_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    shift_id UUID NOT NULL REFERENCES cash_shifts(shift_id) ON DELETE CASCADE,
    tenant_id INT NOT NULL,
    subscriber_id UUID NOT NULL,
    invoice_id UUID NOT NULL,
    receipt_number VARCHAR(64) NOT NULL UNIQUE,
    amount_bdt NUMERIC(10,2) NOT NULL,
    collected_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cash_collections_shift ON cash_collections (shift_id);
CREATE INDEX IF NOT EXISTS idx_cash_collections_sub ON cash_collections (subscriber_id);

-- P1-19: Outbound Webhook Subscriptions & Deliveries
CREATE TABLE IF NOT EXISTS webhook_subscriptions (
    subscription_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    target_url VARCHAR(255) NOT NULL,
    secret VARCHAR(64) NOT NULL,
    events JSONB NOT NULL DEFAULT '[]'::jsonb, -- json array of event names e.g. ["invoice.paid", "subscriber.created"]
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    delivery_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    subscription_id UUID NOT NULL REFERENCES webhook_subscriptions(subscription_id) ON DELETE CASCADE,
    tenant_id INT NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    payload JSONB NOT NULL,
    response_status INT,
    response_body TEXT,
    attempt_count INT NOT NULL DEFAULT 1,
    status VARCHAR(32) NOT NULL DEFAULT 'pending', -- 'pending', 'success', 'failed'
    delivered_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_webhook_deliv_sub ON webhook_deliveries (subscription_id, status);
