-- =============================================================================
-- PostgreSQL 16: Automatic Monthly radacct Partition Maintenance & Retention
-- Project: Carrier-Grade ISP Billing & FreeRADIUS AAA
-- =============================================================================

-- Stored procedure to automatically create future radacct monthly partitions
-- and prune/detach expired partitions beyond the BTRC regulatory retention period.
CREATE OR REPLACE FUNCTION manage_radacct_monthly_partitions(
    months_ahead INT DEFAULT 6,
    retention_months INT DEFAULT 24
)
RETURNS TABLE(partition_name TEXT, start_date TIMESTAMPTZ, end_date TIMESTAMPTZ, status TEXT)
LANGUAGE plpgsql
AS $$
DECLARE
    curr_date DATE := date_trunc('month', CURRENT_DATE)::DATE;
    target_date DATE;
    p_name TEXT;
    start_ts TIMESTAMPTZ;
    end_ts TIMESTAMPTZ;
    table_exists BOOLEAN;
    drop_cutoff DATE;
    old_p_name TEXT;
    r RECORD;
BEGIN
    -- 1. Create Future & Present Partitions
    FOR i IN 0..months_ahead LOOP
        target_date := (curr_date + (i || ' month')::INTERVAL)::DATE;
        p_name := 'radacct_y' || to_char(target_date, 'YYYY') || 'm' || to_char(target_date, 'MM');
        start_ts := target_date::TIMESTAMPTZ;
        end_ts := (target_date + INTERVAL '1 month')::TIMESTAMPTZ;

        SELECT EXISTS (
            SELECT 1 FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = p_name AND n.nspname = 'public'
        ) INTO table_exists;

        IF NOT table_exists THEN
            EXECUTE format(
                'CREATE TABLE IF NOT EXISTS %I PARTITION OF radacct FOR VALUES FROM (%L) TO (%L);',
                p_name, start_ts, end_ts
            );
            partition_name := p_name;
            start_date := start_ts;
            end_date := end_ts;
            status := 'CREATED';
            RETURN NEXT;
        ELSE
            partition_name := p_name;
            start_date := start_ts;
            end_date := end_ts;
            status := 'EXISTS';
            RETURN NEXT;
        END IF;
    END LOOP;

    -- 2. Prune / Detach Partitions Older Than retention_months
    drop_cutoff := (curr_date - (retention_months || ' month')::INTERVAL)::DATE;

    FOR r IN
        SELECT c.relname AS child_table
        FROM pg_inherits
        JOIN pg_class c ON pg_inherits.inhrelid = c.oid
        JOIN pg_class p ON pg_inherits.inhparent = p.oid
        WHERE p.relname = 'radacct'
    LOOP
        -- Parse date from radacct_yYYYYmMM
        IF r.child_table ~ '^radacct_y[0-9]{4}m[0-9]{2}$' THEN
            DECLARE
                part_year INT := substring(r.child_table from 10 for 4)::INT;
                part_month INT := substring(r.child_table from 15 for 2)::INT;
                part_date DATE := make_date(part_year, part_month, 1);
            BEGIN
                IF part_date < drop_cutoff THEN
                    EXECUTE format('ALTER TABLE radacct DETACH PARTITION %I CONCURRENTLY;', r.child_table);
                    partition_name := r.child_table;
                    start_date := part_date::TIMESTAMPTZ;
                    end_date := (part_date + INTERVAL '1 month')::TIMESTAMPTZ;
                    status := 'DETACHED_EXPIRED';
                    RETURN NEXT;
                END IF;
            END;
        END IF;
    END LOOP;

    RETURN;
END;
$$;

-- Pre-seed current month and next 6 months of partitions immediately
SELECT * FROM manage_radacct_monthly_partitions(6, 24);
