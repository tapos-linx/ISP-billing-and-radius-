<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\NetworkAlarm;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * P1-8: Topological Alarm Correlation & Root Cause Suppression Service
 * Correlates downstream subscriber/ONU alarms against upstream aggregation switch
 * and OLT port failures to eliminate alarm storms and isolate true root causes.
 */
class AlarmSuppressionService
{
    /**
     * Correlate an incoming child alarm against an active parent infrastructure alarm
     */
    public function correlateWithParent(NetworkAlarm $childAlarm, NetworkAlarm $parentAlarm): NetworkAlarm
    {
        if ($parentAlarm->status === 'active' && $parentAlarm->alarm_id !== $childAlarm->alarm_id) {
            $childAlarm->is_root_cause = false;
            $childAlarm->suppressed_by_alarm_id = $parentAlarm->alarm_id;
            $childAlarm->status = 'suppressed';
            $childAlarm->save();
        }

        return $childAlarm;
    }

    /**
     * Batch suppress child alarms under a recognized root-cause failure
     *
     * @param  array<int, int>  $childAlarmIds
     */
    public function batchSuppress(NetworkAlarm $parentAlarm, array $childAlarmIds): int
    {
        if (empty($childAlarmIds)) {
            return 0;
        }

        return NetworkAlarm::withoutGlobalScopes()
            ->whereIn('alarm_id', $childAlarmIds)
            ->where('alarm_id', '!=', $parentAlarm->alarm_id)
            ->update([
                'is_root_cause' => false,
                'suppressed_by_alarm_id' => $parentAlarm->alarm_id,
                'status' => 'suppressed',
            ]);
    }

    /**
     * Resolve a root-cause parent alarm and clear or reactivate its suppressed children
     */
    public function resolveParentAlarm(NetworkAlarm $parentAlarm, bool $autoClearChildren = true): int
    {
        $parentAlarm->status = 'cleared';
        $parentAlarm->cleared_at = Carbon::now();
        $parentAlarm->save();

        if ($autoClearChildren) {
            return NetworkAlarm::withoutGlobalScopes()
                ->where('suppressed_by_alarm_id', $parentAlarm->alarm_id)
                ->where('status', 'suppressed')
                ->update([
                    'status' => 'cleared',
                    'cleared_at' => Carbon::now(),
                ]);
        }

        // Reactivate if not auto-cleared
        return NetworkAlarm::withoutGlobalScopes()
            ->where('suppressed_by_alarm_id', $parentAlarm->alarm_id)
            ->where('status', 'suppressed')
            ->update([
                'status' => 'active',
                'suppressed_by_alarm_id' => null,
                'is_root_cause' => true,
            ]);
    }

    /**
     * Retrieve all suppressed alarms linked to a root cause
     *
     * @return Collection<int, NetworkAlarm>
     */
    public function getSuppressedAlarms(int $parentAlarmId): Collection
    {
        return NetworkAlarm::withoutGlobalScopes()
            ->where('suppressed_by_alarm_id', $parentAlarmId)
            ->get();
    }
}
