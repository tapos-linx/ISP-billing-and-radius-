<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Compliance\ComplianceReportingService;
use Illuminate\Console\Command;

class ReportBtrcComplianceCommand extends Command
{
    protected $signature = 'report:btrc-compliance {--month= : Target month in YYYY-MM format}';

    protected $description = 'Generate and display BTRC ISP licensing regulatory compliance return';

    public function handle(ComplianceReportingService $compliance): int
    {
        $month = $this->option('month') ?: date('Y-m');
        $this->info("Generating BTRC Regulatory Compliance Return for period: {$month}...");

        $report = $compliance->generateBtrcRegulatoryReport($month);

        $this->table(
            ['Parameter', 'Audit Value'],
            [
                ['Regulatory Period', $report['report_period']],
                ['BTRC Operator License Tier', $report['btrc_tier']],
                ['Regulatory Compliance Status', $report['compliance_status']],
                ['Active Subscribers Count', $report['total_active_subscribers']],
                ['Expired / Suspended Subscribers', $report['total_expired_subscribers']],
                ['CGNAT Accounting Sessions', $report['cgnat_allocated_sessions']],
                ['Total Volume Consumed (GB)', number_format($report['total_traffic_gb'], 2) . ' GB'],
            ]
        );

        $this->info("[SUCCESS] BTRC regulatory return recorded in btrc_regulatory_reports.");
        return Command::SUCCESS;
    }
}
