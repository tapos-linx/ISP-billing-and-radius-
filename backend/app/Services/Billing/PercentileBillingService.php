<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\BandwidthSample95th;
use App\Models\EnterpriseCircuit;
use Carbon\Carbon;

/**
 * P2-7: Corporate Leased Line 95th Percentile Burstable Bandwidth Billing Service
 * Discards the top 5% peak samples over the billing cycle and rates the 95th percentile peak Mbps.
 */
class PercentileBillingService
{
    /**
     * Ingest periodic (e.g. 5-minute) ingress/egress bandwidth sample
     */
    public function recordSample(string $circuitId, float $inMbps, float $outMbps, ?Carbon $sampledAt = null): BandwidthSample95th
    {
        /** @var EnterpriseCircuit $circuit */
        $circuit = EnterpriseCircuit::withoutGlobalScopes()->findOrFail($circuitId);

        $peak = max($inMbps, $outMbps);

        /** @var BandwidthSample95th $sample */
        $sample = BandwidthSample95th::withoutGlobalScopes()->create([
            'circuit_id' => $circuit->circuit_id,
            'tenant_id' => $circuit->tenant_id,
            'sampled_at' => $sampledAt ?? Carbon::now(),
            'in_mbps' => $inMbps,
            'out_mbps' => $outMbps,
            'peak_mbps' => $peak,
        ]);

        return $sample;
    }

    /**
     * Compute 95th percentile burstable bandwidth and billable overage for billing cycle
     *
     * @return array<string, mixed>
     */
    public function calculate95thPercentile(string $circuitId, Carbon $startDate, Carbon $endDate, float $overageRatePerMbps = 250.0): array
    {
        /** @var EnterpriseCircuit $circuit */
        $circuit = EnterpriseCircuit::withoutGlobalScopes()->findOrFail($circuitId);

        $samples = BandwidthSample95th::withoutGlobalScopes()
            ->where('circuit_id', $circuitId)
            ->whereBetween('sampled_at', [$startDate, $endDate])
            ->pluck('peak_mbps')
            ->toArray();

        $sampleCount = count($samples);
        if ($sampleCount === 0) {
            return [
                'circuit_id' => $circuitId,
                'circuit_ref' => $circuit->circuit_ref,
                'sample_count' => 0,
                'committed_mbps' => $circuit->committed_bandwidth_mbps,
                'percentile_95th_mbps' => (float) $circuit->committed_bandwidth_mbps,
                'overage_mbps' => 0.0,
                'base_charge_bdt' => $circuit->monthly_rate_bdt,
                'overage_charge_bdt' => 0.0,
                'total_billable_bdt' => $circuit->monthly_rate_bdt,
            ];
        }

        // Sort ascending
        sort($samples, SORT_NUMERIC);

        // 95th percentile ranking:
        // Top 5% samples are discarded. In 0-indexed array, index is ceil(N * 0.95) - 1.
        $p95Index = (int) max(0, min($sampleCount - 1, ceil($sampleCount * 0.95) - 1));

        $p95Value = (float) $samples[$p95Index];
        $overageMbps = max(0.0, round($p95Value - $circuit->committed_bandwidth_mbps, 2));
        $overageCharge = round($overageMbps * $overageRatePerMbps, 2);
        $totalBillable = round($circuit->monthly_rate_bdt + $overageCharge, 2);

        return [
            'circuit_id' => $circuitId,
            'circuit_ref' => $circuit->circuit_ref,
            'sample_count' => $sampleCount,
            'committed_mbps' => $circuit->committed_bandwidth_mbps,
            'percentile_95th_mbps' => round($p95Value, 2),
            'overage_mbps' => $overageMbps,
            'base_charge_bdt' => $circuit->monthly_rate_bdt,
            'overage_charge_bdt' => $overageCharge,
            'total_billable_bdt' => $totalBillable,
        ];
    }
}
