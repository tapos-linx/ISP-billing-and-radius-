<?php

declare(strict_types=1);

namespace App\Services\Voip;

use App\Models\DialPlanRate;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Redis;

/**
 * Release 2.0 (Epic 2): Real-Time FreeSWITCH ESL / Kamailio SIP Signaling Bridge Service
 */
class SipSignalingBridgeService
{
    public function __construct(
        protected VoipCdrRatingService $cdrRatingService
    ) {}

    /**
     * Authorize an inbound SIP INVITE request, verify prepaid balance & reserve credit in Redis.
     *
     * @param  array{
     *     call_id: string,
     *     caller_e164: string,
     *     callee_e164: string,
     *     subscriber_id?: string,
     *     trunk_id?: string,
     *     estimated_duration_seconds?: int
     * }  $data
     * @return array{
     *     authorized: bool,
     *     sip_code: int,
     *     reason?: string,
     *     call_id: string,
     *     destination_prefix?: string,
     *     rate_per_minute_bdt?: float,
     *     pulse_seconds?: int,
     *     max_duration_seconds?: int,
     *     reserved_credit_bdt?: float
     * }
     */
    public function authorizeInvite(int $tenantId, array $data): array
    {
        $callId = (string) $data['call_id'];
        $caller = (string) $data['caller_e164'];
        $callee = (string) $data['callee_e164'];

        // 1. Resolve Subscriber or Trunk account balance
        $balanceBdt = 100.00; // Default carrier test line credit
        if (! empty($data['subscriber_id'])) {
            /** @var Subscriber|null $sub */
            $sub = Subscriber::withoutGlobalScopes()->find($data['subscriber_id']);
            if ($sub) {
                $balanceBdt = max(0.0, (float) ($sub->pro_rata_balance ?? 50.00));
            }
        }

        // 2. Longest E.164 Prefix Matching for Destination Rating
        $rate = $this->matchDialPlanRate($tenantId, $callee);
        $ratePerMin = $rate ? (float) $rate->rate_per_minute_bdt : 1.20;
        $pulse = $rate ? max(1, (int) $rate->pulse_seconds) : 1;
        $prefix = $rate ? (string) $rate->destination_prefix : 'DEFAULT';

        // 3. Minimum Credit Check (at least one pulse charge required)
        $minCharge = round(($pulse / 60.0) * $ratePerMin, 4);
        if ($balanceBdt < $minCharge) {
            return [
                'authorized' => false,
                'sip_code' => 402,
                'reason' => 'Payment Required: Insufficient balance for destination',
                'call_id' => $callId,
            ];
        }

        // 4. Calculate Maximum Allowable Call Duration
        $maxDurationSeconds = (int) floor(($balanceBdt / $ratePerMin) * 60.0);

        // 5. Atomic Redis Credit Reservation (<0.5ms fast-path)
        $reserveKey = "sip:reserve:{$callId}";
        $reservePayload = json_encode([
            'tenant_id' => $tenantId,
            'caller_e164' => $caller,
            'callee_e164' => $callee,
            'rate_per_minute_bdt' => $ratePerMin,
            'pulse_seconds' => $pulse,
            'reserved_credit_bdt' => $balanceBdt,
            'created_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        Redis::setex($reserveKey, 7200, $reservePayload);

        return [
            'authorized' => true,
            'sip_code' => 200,
            'call_id' => $callId,
            'destination_prefix' => $prefix,
            'rate_per_minute_bdt' => $ratePerMin,
            'pulse_seconds' => $pulse,
            'max_duration_seconds' => $maxDurationSeconds,
            'reserved_credit_bdt' => $balanceBdt,
        ];
    }

    /**
     * Process SIP BYE call termination event: rate CDR and reconcile reserved credit.
     *
     * @param  array{
     *     call_id: string,
     *     caller_e164: string,
     *     callee_e164: string,
     *     started_at: string,
     *     answered_at?: string,
     *     ended_at: string,
     *     disposition?: string,
     *     trunk_id?: string
     * }  $data
     * @return array{
     *     call_id: string,
     *     status: string,
     *     cdr_id: string,
     *     duration_seconds: int,
     *     billable_seconds: int,
     *     cost_bdt: float,
     *     released_credit_bdt: float
     * }
     */
    public function processBye(int $tenantId, array $data): array
    {
        $callId = (string) $data['call_id'];

        // Ingest and rate CDR
        $cdr = $this->cdrRatingService->ingestAndRateCdr($tenantId, $data);

        // Reconcile Redis credit reservation
        $reserveKey = "sip:reserve:{$callId}";
        $reserveRaw = Redis::get($reserveKey);
        $reservedCredit = 0.00;

        if ($reserveRaw && is_string($reserveRaw)) {
            $parsed = json_decode($reserveRaw, true);
            if (is_array($parsed) && isset($parsed['reserved_credit_bdt'])) {
                $reservedCredit = (float) $parsed['reserved_credit_bdt'];
            }
            Redis::del($reserveKey);
        }

        $releasedCredit = max(0.00, round($reservedCredit - (float) $cdr->cost_bdt, 4));

        return [
            'call_id' => $callId,
            'status' => 'settled',
            'cdr_id' => (string) $cdr->cdr_id,
            'duration_seconds' => (int) $cdr->duration_seconds,
            'billable_seconds' => (int) $cdr->billable_seconds,
            'cost_bdt' => (float) $cdr->cost_bdt,
            'released_credit_bdt' => $releasedCredit,
        ];
    }

    /**
     * Release credit reservation on SIP CANCEL or call failure.
     */
    public function releaseReservation(string $callId): array
    {
        $reserveKey = "sip:reserve:{$callId}";
        $deleted = Redis::del($reserveKey);

        return [
            'call_id' => $callId,
            'status' => 'released',
            'was_reserved' => ($deleted > 0),
        ];
    }

    /**
     * Longest E.164 dial plan prefix lookup helper.
     */
    protected function matchDialPlanRate(int $tenantId, string $callee): ?DialPlanRate
    {
        $clean = preg_replace('/[^\+\d]/', '', $callee) ?? '';

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
