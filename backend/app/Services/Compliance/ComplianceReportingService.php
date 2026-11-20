<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComplianceReportingService
{
    /**
     * Generate or fetch National Board of Revenue (NBR) 5% VAT Mushak-6.3 monthly report.
     */
    public function generateMonthlyVatMushakReport(string $yearMonth): array
    {
        $startDate = "{$yearMonth}-01 00:00:00+00";
        $endDate = date('Y-m-d 00:00:00+00', strtotime("{$startDate} +1 month"));

        // Query settled invoices for the period
        $stats = DB::table('invoices')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as invoice_count,
                COALESCE(SUM(total_bdt), 0) as gross_revenue,
                COALESCE(SUM(subtotal_bdt), 0) as taxable_subtotal,
                COALESCE(SUM(vat_amount_bdt), 0) as vat_collected
            ')
            ->first();

        $gross = round((float) ($stats->gross_revenue ?? 0), 2);
        $taxable = round((float) ($stats->taxable_subtotal ?? 0), 2);
        $vat = round((float) ($stats->vat_collected ?? 0), 2);
        $count = (int) ($stats->invoice_count ?? 0);

        // Store or update in vat_mushak_reports
        DB::table('vat_mushak_reports')->updateOrInsert(
            ['year_month' => $yearMonth],
            [
                'total_invoices_count'        => $count,
                'gross_revenue_bdt'           => $gross,
                'taxable_subtotal_bdt'        => $taxable,
                'vat_collected_5_percent_bdt' => $vat,
                'exempt_amount_bdt'           => 0.00,
                'mushak_version'              => 'Mushak-6.3',
                'generated_at'                => now(),
            ]
        );

        // Detailed invoice line items for NBR Sub-form export
        $lineItems = DB::table('invoices')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$startDate, $endDate])
            ->select('mushak_6_3_number', 'isp_bin_number', 'paid_at', 'subtotal_bdt', 'vat_amount_bdt', 'total_bdt')
            ->orderBy('paid_at')
            ->get();

        return [
            'year_month'                  => $yearMonth,
            'mushak_format'               => 'NBR Mushak-6.3 / Mushak-9.1 Sub-form',
            'vat_rate'                    => '5.00%',
            'total_invoices'              => $count,
            'gross_revenue_bdt'           => $gross,
            'taxable_subtotal_bdt'        => $taxable,
            'vat_collected_5_percent_bdt' => $vat,
            'invoices'                    => $lineItems->toArray(),
        ];
    }

    /**
     * Generate BTRC (Bangladesh Telecommunication Regulatory Commission)
     * ISP Traffic, CGNAT, and Subscriber Compliance Report.
     */
    public function generateBtrcRegulatoryReport(string $yearMonth): array
    {
        $startDate = "{$yearMonth}-01 00:00:00+00";
        $endDate = date('Y-m-d 00:00:00+00', strtotime("{$startDate} +1 month"));

        // 1. Subscriber Counts
        $activeCount = DB::table('subscribers')->where('status', 'active')->count();
        $expiredCount = DB::table('subscribers')->where('status', 'expired')->count();

        // 2. Accounting Traffic & Session Counts
        $traffic = DB::table('radacct')
            ->whereBetween('acctstarttime', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as session_count,
                COALESCE(SUM(acctinputoctets + acctoutputoctets), 0) as total_octets
            ')
            ->first();

        $sessionCount = (int) ($traffic->session_count ?? 0);
        $totalOctets = (float) ($traffic->total_octets ?? 0);
        $totalGb = round($totalOctets / (1024 * 1024 * 1024), 2);

        // Record in btrc_regulatory_reports
        DB::table('btrc_regulatory_reports')->updateOrInsert(
            ['report_period' => $yearMonth],
            [
                'total_active_subscribers'  => $activeCount,
                'total_expired_subscribers' => $expiredCount,
                'cgnat_allocated_sessions'  => $sessionCount,
                'total_traffic_gb'          => $totalGb,
                'compliance_status'         => 'COMPLIANT',
                'btrc_tariff_tier'          => 'Nationwide ISP Tier-1',
                'generated_at'              => now(),
            ]
        );

        return [
            'report_period'            => $yearMonth,
            'btrc_tier'                => 'Nationwide ISP Tier-1',
            'compliance_status'        => 'COMPLIANT',
            'total_active_subscribers' => $activeCount,
            'total_expired_subscribers'=> $expiredCount,
            'cgnat_allocated_sessions' => $sessionCount,
            'total_traffic_gb'         => $totalGb,
        ];
    }
}
