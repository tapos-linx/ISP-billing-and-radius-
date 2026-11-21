-- P0-8: Tokenized Saved-Card Auto-Pay Storage
-- Stores gateway payment tokens only (never raw PAN or CVV)

CREATE TABLE IF NOT EXISTS payment_tokens (
    token_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    subscriber_id UUID NOT NULL,
    gateway VARCHAR(32) NOT NULL, -- 'stripe', 'sslcommerz'
    customer_token VARCHAR(255) NOT NULL, -- customer ID or tokenized profile ID
    payment_method_token VARCHAR(255) NOT NULL, -- pm_xxx or recurring card token
    card_brand VARCHAR(32), -- 'visa', 'mastercard', 'amex'
    card_last4 VARCHAR(4),
    exp_month INT,
    exp_year INT,
    is_default BOOLEAN NOT NULL DEFAULT true,
    status VARCHAR(32) NOT NULL DEFAULT 'active', -- 'active', 'expired', 'revoked'
    retry_count INT NOT NULL DEFAULT 0,
    last_retry_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_payment_tokens_sub ON payment_tokens (subscriber_id, tenant_id);
CREATE INDEX IF NOT EXISTS idx_payment_tokens_status ON payment_tokens (status);
