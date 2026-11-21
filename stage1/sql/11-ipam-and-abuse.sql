-- P0-12: Full IPAM & Regulatory Abuse History Schema

CREATE TABLE IF NOT EXISTS ip_assignment_history (
    id BIGSERIAL PRIMARY KEY,
    tenant_id INT NOT NULL,
    subscriber_id UUID NOT NULL,
    framed_ip VARCHAR(45) NOT NULL,
    delegated_ipv6_prefix VARCHAR(64),
    nas_ip VARCHAR(45),
    mac_address VARCHAR(17),
    assigned_at TIMESTAMPTZ NOT NULL,
    released_at TIMESTAMPTZ,
    session_id VARCHAR(64),
    retained_until TIMESTAMPTZ NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '730 days')
);

CREATE INDEX IF NOT EXISTS idx_ip_history_lookup ON ip_assignment_history (framed_ip, assigned_at, released_at);
CREATE INDEX IF NOT EXISTS idx_ip_history_sub ON ip_assignment_history (subscriber_id);

-- Enhance ip_pools with hierarchy and IPv6-PD fields if not present
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='ip_pools' AND column_name='parent_pool_id') THEN
        ALTER TABLE ip_pools ADD COLUMN parent_pool_id UUID;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='ip_pools' AND column_name='ip_version') THEN
        ALTER TABLE ip_pools ADD COLUMN ip_version VARCHAR(4) DEFAULT 'v4';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='ip_pools' AND column_name='delegated_prefix_len') THEN
        ALTER TABLE ip_pools ADD COLUMN delegated_prefix_len INT DEFAULT 64;
    END IF;
END $$;
