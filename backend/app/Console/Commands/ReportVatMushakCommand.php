<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Compliance\ComplianceReportingService;
use Illuminate\Console\Command;

class ReportVatMushakCommand extends Command
{
    protected $signature = 'report:vat-mushak {--month= : Target month in YYYY-MM format}';

    protected $description = 'Generate and display NBR 5% VAT Mushak-6.3 monthly return ledger';

    public function handle(ComplianceReportingService $compliance): int
    {
        $month = $this->option('month') ?: date('Y-m');
        $this->info("Generating NBR 5% VAT Mushak-6.3 Return for period: {$month}...");

        $report = $compliance->generateMonthlyVatMushakReport($month);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Tax Period', $report['year_month']],
                ['Statutory Tax Format', $report['mushak_format']],
                ['Statutory VAT Rate', $report['vat_rate']],
                ['Settled Invoices Count', $report['total_invoices']],
                ['Gross Revenue (BDT)', number_format($report['gross_revenue_bdt'], 2)],
                ['Taxable Subtotal (BDT)', number_format($report['taxable_subtotal_bdt'], 2)],
                ['VAT Collected (5% Mushak-6.3)', number_format($report['vat_collected_5_percent_bdt'], 2)],
            ]
        );

        $this->info('[SUCCESS] Mushak-6.3 VAT return aggregated and recorded in vat_mushak_reports.');

        return Command::SUCCESS;
    }
}
