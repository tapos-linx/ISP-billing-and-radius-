<?php

declare(strict_types=1);

namespace App\Services\Voip;

use App\Models\DialPlanRate;
use App\Models\SipTrunk;
use App\Models\VoipCdr;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * P2-12: SIP / VoIP Call Detail Record (CDR) Ingestion & E.164 Dial-Plan Rating Service
 */
class VoipCdrRatingService
{
    /**
     * Register an interconnect SIP trunk
     *
     * @param  array<string, mixed>  $data
     */
    public function registerTrunk(int $tenantId, array $data): SipTrunk
    {
        /** @var SipTrunk $trunk */
        $trunk = SipTrunk::withoutGlobalScopes()->create([
            'trunk_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'name' => (string) $data['name'],
            'host' => (string) $data['host'],
            'port' => isset($data['port']) ? (int) $data['port'] : 5060,
            'auth_username' => isset($data['auth_username']) ? (string) $data['auth_username'] : null,
            'auth_password' => isset($data['auth_password']) ? (string) $data['auth_password'] : null,
            'max_channels' => isset($data['max_channels']) ? (int) $data['max_channels'] : 30,
            'status' => 'registered',
            'created_at' => Carbon::now(),
        ]);

        return $trunk;
    }

    /**
     * Ingest and rate an RFC 3261 Call Detail Record against the active E.164 dial-plan
     *
     * @param  array<string, mixed>  $data
     */
    public function ingestAndRateCdr(int $tenantId, array $data): VoipCdr
    {
        $startedAt = Carbon::parse($data['started_at']);
        $endedAt = Carbon::parse($data['ended_at']);
        $answeredAt = isset($data['answered_at']) ? Carbon::parse($data['answered_at']) : null;

        $duration = max(0, (int) $startedAt->diffInSeconds($endedAt));
        $billableSeconds = 0;
        $costBdt = 0.0;
        $ratePerMin = 0.0;

        $disposition = (string) ($data['disposition'] ?? ($answeredAt ? 'ANSWERED' : 'NO ANSWER'));

        if ($disposition === 'ANSWERED' && $answeredAt !== null) {
            $actualCallDuration = max(0, (int) $answeredAt->diffInSeconds($endedAt));

            // Find matching dial plan rate by longest prefix match
            $callee = (string) $data['callee_e164'];
            $rate = $this->matchDialPlanRate($tenantId, $callee);

            if ($rate !== null) {
                $ratePerMin = (float) $rate->rate_per_minute_bdt;
                $pulse = max(1, (int) $rate->pulse_seconds);

                // Round up to nearest pulse
                $billableSeconds = (int) (ceil($actualCallDuration / $pulse) * $pulse);
                $costBdt = round(($billableSeconds / 60.0) * $ratePerMin, 4);
            } else {
                $billableSeconds = $actualCallDuration;
                $ratePerMin = 1.00; // Default fallback rate
                $costBdt = round(($billableSeconds / 60.0) * $ratePerMin, 4);
            }
        }

        /** @var VoipCdr $cdr */
        $cdr = VoipCdr::withoutGlobalScopes()->create([
            'cdr_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'trunk_id' => $data['trunk_id'] ?? null,
            'call_id' => (string) ($data['call_id'] ?? Str::uuid().'@carrier.sip'),
            'caller_e164' => (string) $data['caller_e164'],
            'callee_e164' => (string) $data['callee_e164'],
            'started_at' => $startedAt,
            'answered_at' => $answeredAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $duration,
            'billable_seconds' => $billableSeconds,
            'rate_per_minute_bdt' => $ratePerMin,
            'cost_bdt' => $costBdt,
            'disposition' => $disposition,
            'created_at' => Carbon::now(),
        ]);

        return $cdr;
    }

    /**
     * Longest Prefix Match against E.164 Dial Plan
     */
    protected function matchDialPlanRate(int $tenantId, string $callee): ?DialPlanRate
    {
        $clean = preg_replace('/[^\+\d]/', '', $callee) ?? '';

        /** @var Collection<int, DialPlanRate> $rates */
        $rates = DialPlanRate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $bestMatch = null;
        $longestPrefixLen = -1;

        foreach ($rates as $rate) {
            $prefix = (string) $rate->destination_prefix;
            if (str_starts_with($clean, $prefix) && strlen($prefix) > $longestPrefixLen) {
                $bestMatch = $rate;
                $longestPrefixLen = strlen($prefix);
            }
        }

        return $bestMatch;
    }
}
