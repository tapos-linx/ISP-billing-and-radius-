<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\CapacityForecast;
use App\Models\FieldTechnician;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * P2-13: AI Capacity Exhaustion Forecasting & Nearest-Technician Splicing Dispatch Optimizer
 */
class CapacityForecastingService
{
    /**
     * Compute days until bandwidth or subscriber exhaustion and recommend carrier intervention
     */
    public function forecastResource(int $tenantId, string $resourceType, string $resourceId, float $currentUtilPct, float $dailyGrowthPct): CapacityForecast
    {
        $remainingPct = max(0.0, 100.0 - $currentUtilPct);
        $growthRate = max(0.01, $dailyGrowthPct);

        $daysToExhaustion = (int) ceil($remainingPct / $growthRate);

        $recommendation = match (true) {
            $daysToExhaustion <= 7 => "CRITICAL: {$resourceType} {$resourceId} will saturate within {$daysToExhaustion} days. Perform immediate split or augment 10G uplink.",
            $daysToExhaustion <= 30 => "WARNING: Capacity threshold approaching in {$daysToExhaustion} days. Schedule card upgrade or rebalance PON splitters.",
            default => "OPTIMAL: {$resourceType} utilization healthy. Projected runway: {$daysToExhaustion} days.",
        };

        /** @var CapacityForecast $forecast */
        $forecast = CapacityForecast::withoutGlobalScopes()->create([
            'forecast_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'current_utilization_pct' => $currentUtilPct,
            'daily_growth_rate_pct' => $dailyGrowthPct,
            'days_to_exhaustion' => $daysToExhaustion,
            'recommended_action' => $recommendation,
            'forecasted_at' => Carbon::now(),
        ]);

        return $forecast;
    }

    /**
     * Find and dispatch the nearest available fiber technician to an optical break coordinate
     *
     * @return array<string, mixed>|null
     */
    public function findNearestTechnician(int $tenantId, float $faultLat, float $faultLng): ?array
    {
        /** @var Collection<int, FieldTechnician> $techs */
        $techs = FieldTechnician::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'available')
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->get();

        if ($techs->isEmpty()) {
            return null;
        }

        $closestTech = null;
        $shortestDistanceKm = PHP_FLOAT_MAX;

        foreach ($techs as $tech) {
            $lat = (float) $tech->current_lat;
            $lng = (float) $tech->current_lng;

            // Haversine distance in kilometers
            $dLat = deg2rad($faultLat - $lat);
            $dLng = deg2rad($faultLng - $lng);
            $a = sin($dLat / 2) * sin($dLat / 2) +
                cos(deg2rad($lat)) * cos(deg2rad($faultLat)) *
                sin($dLng / 2) * sin($dLng / 2);
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            $distKm = 6371.0 * $c;

            if ($distKm < $shortestDistanceKm) {
                $shortestDistanceKm = $distKm;
                $closestTech = $tech;
            }
        }

        if ($closestTech === null) {
            return null;
        }

        return [
            'technician_id' => $closestTech->technician_id,
            'name' => $closestTech->name,
            'phone' => $closestTech->phone,
            'zone' => $closestTech->zone,
            'distance_km' => round($shortestDistanceKm, 2),
            'estimated_eta_minutes' => (int) round(($shortestDistanceKm / 25.0) * 60) + 5, // Avg 25 km/h city speed
        ];
    }
}
