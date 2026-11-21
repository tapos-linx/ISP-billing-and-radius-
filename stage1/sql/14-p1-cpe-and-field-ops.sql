-- Phase 2 (P1 Batch 2): CPE Management, Optical Alarms & Field Operations Schema

CREATE TABLE IF NOT EXISTS field_technicians (
    technician_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    name VARCHAR(64) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    email VARCHAR(128),
    zone VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'available', -- 'available', 'on_job', 'off_duty'
    current_lat NUMERIC(10,7),
    current_lng NUMERIC(10,7),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tech_zone ON field_technicians (zone, status);

CREATE TABLE IF NOT EXISTS work_orders (
    order_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    order_number VARCHAR(32) NOT NULL UNIQUE,
    subscriber_id UUID,
    ticket_id UUID,
    technician_id UUID,
    type VARCHAR(32) NOT NULL, -- 'new_install', 'repair', 'fiber_splice', 'onu_replacement'
    priority VARCHAR(16) NOT NULL DEFAULT 'medium',
    status VARCHAR(32) NOT NULL DEFAULT 'pending', -- 'pending', 'assigned', 'in_progress', 'completed', 'cancelled'
    address VARCHAR(255) NOT NULL,
    lat NUMERIC(10,7),
    lng NUMERIC(10,7),
    scheduled_at TIMESTAMPTZ NOT NULL,
    completed_at TIMESTAMPTZ,
    notes TEXT,
    customer_signature VARCHAR(255),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_work_orders_tech ON work_orders (technician_id, status);
CREATE INDEX IF NOT EXISTS idx_work_orders_sub ON work_orders (subscriber_id);

CREATE TABLE IF NOT EXISTS inventory_warehouses (
    warehouse_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    name VARCHAR(64) NOT NULL,
    location VARCHAR(128) NOT NULL,
    manager_name VARCHAR(64),
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO inventory_warehouses (tenant_id, name, location, manager_name)
VALUES
    (1, 'Central NOC Hub', 'Banani Commercial Area, Dhaka', 'Md. Kabir Hossain')
ON CONFLICT DO NOTHING;

CREATE TABLE IF NOT EXISTS inventory_items (
    item_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id INT NOT NULL,
    warehouse_id UUID NOT NULL,
    subscriber_id UUID,
    serial_number VARCHAR(64) NOT NULL UNIQUE,
    model VARCHAR(64) NOT NULL,
    item_type VARCHAR(32) NOT NULL, -- 'onu', 'router', 'sfp', 'drop_cable'
    status VARCHAR(32) NOT NULL DEFAULT 'in_stock', -- 'in_stock', 'assigned', 'faulty', 'scrapped'
    unit_cost_bdt NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_inventory_serial ON inventory_items (serial_number);
CREATE INDEX IF NOT EXISTS idx_inventory_sub ON inventory_items (subscriber_id);

CREATE TABLE IF NOT EXISTS network_alarms (
    alarm_id BIGSERIAL PRIMARY KEY,
    tenant_id INT NOT NULL,
    device_id UUID,
    onu_id UUID,
    alarm_type VARCHAR(64) NOT NULL, -- 'LOS', 'DYING_GASP', 'LINK_DOWN', 'HIGH_ATTENUATION'
    severity VARCHAR(16) NOT NULL, -- 'critical', 'major', 'minor', 'warning'
    is_root_cause BOOLEAN NOT NULL DEFAULT true,
    suppressed_by_alarm_id BIGINT,
    status VARCHAR(32) NOT NULL DEFAULT 'active', -- 'active', 'suppressed', 'cleared'
    detected_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cleared_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_alarms_device ON network_alarms (device_id, status);
CREATE INDEX IF NOT EXISTS idx_alarms_suppressed ON network_alarms (suppressed_by_alarm_id);
