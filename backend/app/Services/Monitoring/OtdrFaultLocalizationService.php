<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\OtdrTrace;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * P2-4: OTDR (Optical Time-Domain Reflectometer) Distance Fault Localization Service
 * Analyzes optical pulse reflection backscatter to pinpoint physical fiber cuts and high-loss splices.
 */
class OtdrFaultLocalizationService
{
    private const SPEED_OF_LIGHT_MS = 299792458.0;

    /**
     * Compute fiber distance from optical round-trip pulse reflection delay
     */
    public function computeDistanceMeters(float $roundTripTimeNs, float $refractiveIndex = 1.4682): float
    {
        // Distance = (c * t_seconds) / (2 * refractive_index)
        $timeSeconds = $roundTripTimeNs * 1e-9;
        $distanceMeters = (self::SPEED_OF_LIGHT_MS * $timeSeconds) / (2.0 * $refractiveIndex);

        return round($distanceMeters, 2);
    }

    /**
     * Analyze an OTDR pulse trace and map to physical field fiber break coordinates
     *
     * @param  array<string, mixed>  $data
     */
    public function analyzeTrace(int $tenantId, array $data): OtdrTrace
    {
        $refractiveIndex = isset($data['fiber_refractive_index']) ? (float) $data['fiber_refractive_index'] : 1.4682;
        $lossDb = (float) $data['optical_loss_db'];

        $distanceMeters = isset($data['fault_distance_meters'])
            ? (float) $data['fault_distance_meters']
            : $this->computeDistanceMeters((float) ($data['round_trip_time_ns'] ?? 0.0), $refractiveIndex);

        $faultType = match (true) {
            $lossDb >= 15.0 => 'fiber_break',
            $lossDb >= 4.0 => 'high_loss_splice',
            default => 'macrobend',
        };

        $km = round($distanceMeters / 1000.0, 2);
        $estimatedAddress = $data['estimated_location_address']
            ?? "Distribution Route ~{$km} km from {$data['circuit_or_port_ref']} Headend";

        /** @var OtdrTrace $trace */
        $trace = OtdrTrace::withoutGlobalScopes()->create([
            'trace_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'circuit_or_port_ref' => (string) $data['circuit_or_port_ref'],
            'optical_wavelength_nm' => (int) ($data['optical_wavelength_nm'] ?? 1550),
            'fiber_refractive_index' => $refractiveIndex,
            'pulse_width_ns' => (int) ($data['pulse_width_ns'] ?? 100),
            'fault_distance_meters' => $distanceMeters,
            'optical_loss_db' => $lossDb,
            'fault_type' => $faultType,
            'estimated_location_address' => $estimatedAddress,
            'traced_at' => Carbon::now(),
        ]);

        return $trace;
    }
}
