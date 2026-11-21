-- P0-13: Helpdesk Ticketing & SLA Engine Schema

CREATE TABLE IF NOT EXISTS ticket_slas (
    sla_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    priority VARCHAR(16) NOT NULL UNIQUE, -- 'low', 'medium', 'high', 'critical'
    response_time_minutes INT NOT NULL,
    resolution_time_minutes INT NOT NULL
);

INSERT INTO ticket_slas (priority, response_time_minutes, resolution_time_minutes)
VALUES
    ('critical', 15, 60),
    ('high', 60, 240),
    ('medium', 120, 480),
    ('low', 240, 1440)
ON CONFLICT (priority) DO NOTHING;

CREATE TABLE IF NOT EXISTS tickets (
    ticket_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    ticket_number VARCHAR(32) NOT NULL UNIQUE,
    subscriber_id UUID,
    subject VARCHAR(255) NOT NULL,
    category VARCHAR(64) NOT NULL, -- 'connectivity', 'billing', 'speed', 'optical_fiber', 'general'
    priority VARCHAR(16) NOT NULL DEFAULT 'medium',
    status VARCHAR(32) NOT NULL DEFAULT 'open', -- 'open', 'in_progress', 'pending_customer', 'resolved', 'closed'
    assigned_to_user_id BIGINT,
    sla_due_at TIMESTAMPTZ,
    resolved_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tickets_tenant ON tickets (tenant_id);
CREATE INDEX IF NOT EXISTS idx_tickets_sub ON tickets (subscriber_id);
CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets (status);
CREATE INDEX IF NOT EXISTS idx_tickets_sla ON tickets (sla_due_at);

CREATE TABLE IF NOT EXISTS ticket_comments (
    comment_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    ticket_id UUID NOT NULL,
    user_id BIGINT,
    is_internal BOOLEAN NOT NULL DEFAULT false,
    comment TEXT NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ticket_comments ON ticket_comments (ticket_id, created_at ASC);

CREATE TABLE IF NOT EXISTS ticket_canned_replies (
    reply_id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id INT NOT NULL,
    title VARCHAR(64) NOT NULL,
    body TEXT NOT NULL
);

INSERT INTO ticket_canned_replies (tenant_id, title, body)
VALUES
    (1, 'Power Cycle Router', 'Please power off your Wi-Fi router for 30 seconds and power it back on. Our NOC has re-synchronized your port.'),
    (1, 'Fiber Cut In Progress', 'A physical fiber core cut has been detected in your area. Our field transmission team has been dispatched to splice the cable.'),
    (1, 'Bill Paid - Restored', 'We have confirmed your payment receipt. Your PPPoE connection has been restored to your full subscribed bandwidth speed.')
ON CONFLICT DO NOTHING;
