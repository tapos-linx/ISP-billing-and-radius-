<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Invoice;
use App\Models\Subscriber;
use App\Models\Tariff;
use Carbon\Carbon;

/**
 * P1-20: Executive Business Intelligence & Telemetry Analytics Service
 * Computes carrier MRR, ARPU, churn percentage, and Accounts Receivable (AR) aging cohorts.
 */
class ExecutiveBiService
{
    /**
     * Compute full executive BI overview for carrier leadership
     *
     * @return array<string, mixed>
     */
    public function getExecutiveOverview(int $tenantId): array
    {
        $activeSubCount = Subscriber::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $totalSubCount = Subscriber::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();

        $churnedSubCount = Subscriber::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['suspended', 'expired', 'terminated'])
            ->count();

        $churnRatePct = $totalSubCount > 0
            ? round(($churnedSubCount / $totalSubCount) * 100.0, 2)
            : 0.0;

        // Calculate Monthly Recurring Revenue (MRR) from active subscribers and tariff prices
        $mrrBdt = (float) Subscriber::withoutGlobalScopes()
            ->join('tariffs', 'subscribers.tariff_id', '=', 'tariffs.tariff_id')
            ->where('subscribers.tenant_id', $tenantId)
            ->where('subscribers.status', 'active')
            ->sum('tariffs.price_bdt');

        // Fallback if price is in price column
        if ($mrrBdt <= 0.0 && $activeSubCount > 0) {
            $tariffs = Tariff::withoutGlobalScopes()->get()->keyBy('tariff_id');
            $activeSubs = Subscriber::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->get();

            foreach ($activeSubs as $sub) {
                $t = $tariffs->get($sub->tariff_id);
                if ($t) {
                    $mrrBdt += (float) ($t->price_bdt ?? $t->price ?? 1000.0);
                } else {
                    $mrrBdt += 1000.0;
                }
            }
        }

        $arpuBdt = $activeSubCount > 0
            ? round($mrrBdt / $activeSubCount, 2)
            : 0.0;

        $arAging = $this->calculateArAgingBuckets($tenantId);

        return [
            'tenant_id' => $tenantId,
            'active_subscribers' => $activeSubCount,
            'total_subscribers' => $totalSubCount,
            'churned_subscribers' => $churnedSubCount,
            'churn_rate_pct' => $churnRatePct,
            'mrr_bdt' => round($mrrBdt, 2),
            'arpu_bdt' => $arpuBdt,
            'ar_aging' => $arAging,
        ];
    }

    /**
     * Compute Accounts Receivable (AR) aging buckets (Current 0-30d, 31-60d, 61-90d, 90d+)
     *
     * @return array<string, float>
     */
    public function calculateArAgingBuckets(int $tenantId): array
    {
        $now = Carbon::now();

        $unpaidInvoices = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['issued', 'overdue'])
            ->get();

        $current = 0.0;
        $thirtyToSixty = 0.0;
        $sixtyToNinety = 0.0;
        $ninetyPlus = 0.0;

        foreach ($unpaidInvoices as $inv) {
            $amount = (float) ($inv->total_bdt ?: $inv->amount_bdt);
            $dueDate = $inv->due_date ? Carbon::parse($inv->due_date) : $now;

            $daysOverdue = (int) $dueDate->diffInDays($now, false);

            if ($daysOverdue <= 30) {
                $current += $amount;
            } elseif ($daysOverdue <= 60) {
                $thirtyToSixty += $amount;
            } elseif ($daysOverdue <= 90) {
                $sixtyToNinety += $amount;
            } else {
                $ninetyPlus += $amount;
            }
        }

        return [
            'current_0_30_days_bdt' => round($current, 2),
            'overdue_31_60_days_bdt' => round($thirtyToSixty, 2),
            'overdue_61_90_days_bdt' => round($sixtyToNinety, 2),
            'overdue_90_plus_days_bdt' => round($ninetyPlus, 2),
            'total_outstanding_ar_bdt' => round($current + $thirtyToSixty + $sixtyToNinety + $ninetyPlus, 2),
        ];
    }
}
