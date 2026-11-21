<?php

declare(strict_types=1);

namespace App\Services\Bandwidth;

use App\Models\Subscriber;
use App\Models\TimeOfDayProfile;
use App\Services\Radius\RadiusCoaService;
use Carbon\Carbon;

/**
 * P1-2: Scheduled Time-of-Day Boost & Contention Ratio Management Service
 */
class TimeOfDayBoostService
{
    public function __construct(
        protected RadiusCoaService $coaService
    ) {}

    /**
     * Determine active boost profile for current time
     */
    public function getActiveBoostProfile(?Carbon $now = null): ?TimeOfDayProfile
    {
        $now = $now ?? Carbon::now();
        $currentTime = $now->format('H:i:s');
        $isWeekend = $now->isWeekend();

        $profiles = TimeOfDayProfile::withoutGlobalScopes()
            ->where('is_active', true)
            ->get();

        foreach ($profiles as $profile) {
            if ($profile->days_of_week === 'weekends' && ! $isWeekend) {
                continue;
            }

            if ($profile->start_time <= $currentTime && $currentTime <= $profile->end_time) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * Apply speed boost to a subscriber if an active window matches
     */
    public function evaluateAndApplyBoost(Subscriber $subscriber, ?Carbon $now = null): array
    {
        $activeProfile = $this->getActiveBoostProfile($now);
        $baseRate = $subscriber->bandwidth_profile ?: '20M/20M';

        if ($activeProfile !== null) {
            $boostRate = $activeProfile->boost_rate_limit;

            // Trigger RFC 5176 CoA rate boost
            $this->coaService->changeBandwidthForUser(
                username: $subscriber->username,
                rateLimit: $boostRate,
                subscriberId: (string) $subscriber->subscriber_id
            );

            return [
                'boosted' => true,
                'profile_name' => $activeProfile->name,
                'contention_ratio' => $activeProfile->contention_ratio,
                'boost_rate' => $boostRate,
                'base_rate' => $baseRate,
            ];
        }

        // Window expired or inactive: restore base plan rate
        $this->coaService->changeBandwidthForUser(
            username: $subscriber->username,
            rateLimit: $baseRate,
            subscriberId: (string) $subscriber->subscriber_id
        );

        return [
            'boosted' => false,
            'profile_name' => 'Standard Rate',
            'contention_ratio' => '1:4',
            'boost_rate' => null,
            'base_rate' => $baseRate,
        ];
    }
}
