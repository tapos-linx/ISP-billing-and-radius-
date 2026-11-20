<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaintainRadacctPartitionsCommand extends Command
{
    protected $signature = 'radacct:maintain-partitions {--ahead=6 : Months ahead to pre-create} {--retention=24 : Retention months}';

    protected $description = 'Automatically create future monthly radacct partitions and prune expired partitions';

    public function handle(): int
    {
        $monthsAhead = (int) $this->option('ahead');
        $retentionMonths = (int) $this->option('retention');

        $this->info("Executing radacct partition maintenance (ahead: {$monthsAhead}m, retention: {$retentionMonths}m)...");

        try {
            // Check if stored procedure exists, create if missing
            $exists = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_proc WHERE proname = 'manage_radacct_monthly_partitions'
                ) AS exists;
            ");

            if (! $exists || ! $exists->exists) {
                $sqlPath = database_path('sql/03-partition-maintenance.sql');
                if (file_exists($sqlPath)) {
                    DB::unprepared(file_get_contents($sqlPath));
                    $this->info('Installed manage_radacct_monthly_partitions function from sql file.');
                } else {
                    // Inline creation if file not found
                    DB::unprepared("
                        CREATE OR REPLACE FUNCTION manage_radacct_monthly_partitions(
                            months_ahead INT DEFAULT 6,
                            retention_months INT DEFAULT 24
                        )
                        RETURNS TABLE(partition_name TEXT, start_date TIMESTAMPTZ, end_date TIMESTAMPTZ, status TEXT)
                        LANGUAGE plpgsql
                        AS \$\$
                        DECLARE
                            curr_date DATE := date_trunc('month', CURRENT_DATE)::DATE;
                            target_date DATE;
                            p_name TEXT;
                            start_ts TIMESTAMPTZ;
                            end_ts TIMESTAMPTZ;
                            table_exists BOOLEAN;
                        BEGIN
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
                                    BEGIN
                                        EXECUTE format('CREATE TABLE IF NOT EXISTS %I PARTITION OF radacct FOR VALUES FROM (%L) TO (%L);', p_name, start_ts, end_ts);
                                        partition_name := p_name; start_date := start_ts; end_date := end_ts; status := 'CREATED';
                                        RETURN NEXT;
                                    EXCEPTION WHEN OTHERS THEN
                                        -- Ignore partition collision if parallel worker created it
                                    END;
                                ELSE
                                    partition_name := p_name; start_date := start_ts; end_date := end_ts; status := 'EXISTS';
                                    RETURN NEXT;
                                END IF;
                            END LOOP;
                            RETURN;
                        END;
                        \$\$;
                    ");
                }
            }

            $rows = DB::select('SELECT * FROM manage_radacct_monthly_partitions(?, ?);', [$monthsAhead, $retentionMonths]);

            $created = 0;
            $existing = 0;
            $detached = 0;

            foreach ($rows as $row) {
                if ($row->status === 'CREATED') {
                    $created++;
                    $this->line("  [CREATED] Partition: {$row->partition_name} ({$row->start_date} -> {$row->end_date})");
                } elseif ($row->status === 'EXISTS') {
                    $existing++;
                } elseif ($row->status === 'DETACHED_EXPIRED') {
                    $detached++;
                    $this->warn("  [DETACHED] Expired partition: {$row->partition_name}");
                }
            }

            $this->info("Completed partition maintenance: {$created} created, {$existing} verified, {$detached} detached.");
            Log::info('radacct partition maintenance completed', ['created' => $created, 'existing' => $existing, 'detached' => $detached]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to maintain radacct partitions: '.$e->getMessage());
            Log::error('Partition maintenance failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
