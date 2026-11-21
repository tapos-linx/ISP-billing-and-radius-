<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\NetworkAlarm;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * P1-7: Carrier GPON Optical Alarm Classifier
 * Parses OLT SNMP traps/syslog events, differentiating subscriber electrical
 * power loss (Dying-Gasp capacitor discharge) from distribution fiber cuts (LOS).
 */
class OpticalAlarmService
{
    /**
     * Process an incoming OLT trap or telemetry event and register network alarm
     *
     * @param  array<string, mixed>  $event
     */
    public function processEvent(int $tenantId, array $event): NetworkAlarm
    {
        $alarmType = strtoupper((string) ($event['alarm_type'] ?? 'UNKNOWN'));
        $deviceId = $event['device_id'] ?? null;
        $onuId = $event['onu_id'] ?? null;
        $opticalPower = isset($event['optical_power_dbm']) ? (float) $event['optical_power_dbm'] : null;

        // Auto-classify if only optical power is provided
        if ($opticalPower !== null && $alarmType === 'UNKNOWN') {
            if ($opticalPower <= -35.0) {
                $alarmType = 'LOS';
            } elseif ($opticalPower <= -27.0) {
                $alarmType = 'HIGH_ATTENUATION';
            }
        }

        // Handle recovery / clear event
        if (in_array($alarmType, ['RECOVERY', 'ONLINE', 'CLEARED', 'NORMAL'], true)) {
            $alarm = $this->clearAlarms($tenantId, (string) $deviceId, $onuId ? (string) $onuId : null);

            return $alarm ?? new NetworkAlarm([
                'tenant_id' => $tenantId,
                'device_id' => $deviceId,
                'onu_id' => $onuId,
                'alarm_type' => 'CLEARED',
                'severity' => 'info',
                'status' => 'cleared',
                'cleared_at' => Carbon::now(),
            ]);
        }

        $severity = match ($alarmType) {
            'LOS', 'LINK_DOWN' => 'critical',
            'DYING_GASP' => 'major',
            'HIGH_ATTENUATION' => 'warning',
            default => 'minor',
        };

        /** @var NetworkAlarm $alarm */
        $alarm = NetworkAlarm::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'device_id' => $deviceId,
                'onu_id' => $onuId,
                'alarm_type' => $alarmType,
                'status' => 'active',
            ],
            [
                'severity' => $severity,
                'is_root_cause' => true,
                'detected_at' => Carbon::now(),
                'cleared_at' => null,
            ]
        );

        return $alarm;
    }

    /**
     * Clear active alarms for a device or ONU
     */
    public function clearAlarms(int $tenantId, string $deviceId, ?string $onuId = null): ?NetworkAlarm
    {
        $query = NetworkAlarm::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active');

        if ($deviceId !== '') {
            $query->where('device_id', $deviceId);
        }

        if ($onuId !== null) {
            $query->where('onu_id', $onuId);
        }

        /** @var NetworkAlarm|null $alarm */
        $alarm = $query->latest('detected_at')->first();

        if ($alarm !== null) {
            $alarm->status = 'cleared';
            $alarm->cleared_at = Carbon::now();
            $alarm->save();
        }

        return $alarm;
    }

    /**
     * Retrieve all currently active alarms for the tenant
     *
     * @return Collection<int, NetworkAlarm>
     */
    public function getActiveAlarms(int $tenantId): Collection
    {
        return NetworkAlarm::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderByDesc('detected_at')
            ->get();
    }
}
