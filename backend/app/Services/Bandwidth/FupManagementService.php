<?php

declare(strict_types=1);

namespace App\Services\Bandwidth;

use App\Models\DataTopup;
use App\Models\FupQuota;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Services\Audit\BillingAuditService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Radius\RadiusCoaService;
use App\Services\Sms\SmsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P1-1: Fair Usage Policy (FUP) Engine, Dynamic Throttling & Top-Up Service
 */
class FupManagementService
{
    public function __construct(
        protected RadiusCoaService $coaService,
        protected DoubleEntryLedgerService $ledgerService,
        protected BillingAuditService $auditService,
        protected SmsService $smsService
    ) {}

    /**
     * Check subscriber usage against FUP threshold and apply dynamic CoA throttle if breached
     */
    public function checkAndApplyFup(Subscriber $subscriber): array
    {
        $now = Carbon::now();

        // 1. Fetch or create active FUP quota cycle
        $fup = FupQuota::withoutGlobalScopes()
            ->where('subscriber_id', $subscriber->subscriber_id)
            ->where('cycle_start', '<=', $now)
            ->where('cycle_end', '>=', $now)
            ->first();

        if (! $fup) {
            $fup = $this->initializeQuotaCycle($subscriber, $now);
        }

        // 2. Aggregate current cycle octets from radacct
        $acctBytes = (int) DB::table('radacct')
            ->where('username', $subscriber->username)
            ->where('acctstarttime', '>=', $fup->cycle_start)
            ->selectRaw('COALESCE(SUM(acctinputoctets + acctoutputoctets), 0) as total_bytes')
            ->value('total_bytes');

        $fup->used_bytes = $acctBytes;
        $totalAllowance = $fup->data_limit_bytes + $fup->rollover_bytes;

        $breached = $fup->used_bytes >= $totalAllowance;

        if ($breached && ! $fup->is_throttled) {
            // Apply throttle
            $fup->is_throttled = true;
            $fup->throttled_at = $now;
            $fup->save();

            // Trigger dynamic RFC 5176 CoA rate throttle (<500ms SLA)
            $this->coaService->changeBandwidthForUser(
                username: $subscriber->username,
                rateLimit: $fup->throttle_rate_limit,
                subscriberId: (string) $subscriber->subscriber_id
            );

            // Audit & SMS alert
            $this->auditService->logEvent(
                eventType: 'fup_throttle_applied',
                entityType: 'subscriber',
                entityId: (string) $subscriber->subscriber_id,
                actorType: 'fup_engine',
                actorId: 'scheduler',
                prevState: ['bandwidth_profile' => $subscriber->bandwidth_profile],
                newState: [
                    'used_bytes' => $fup->used_bytes,
                    'limit_bytes' => $totalAllowance,
                    'throttled_profile' => $fup->throttle_rate_limit,
                ]
            );

            $this->smsService->send(
                '01700000000',
                sprintf('Dear %s, you have consumed 100%% of your data quota. Bandwidth reduced to %s. Purchase top-up to restore speed.', $subscriber->username, $fup->throttle_rate_limit),
                'FUP_BREACH'
            );
        } elseif (! $breached && $fup->is_throttled) {
            // Un-throttle (e.g. after top-up)
            $fup->is_throttled = false;
            $fup->throttled_at = null;
            $fup->save();

            $normalProfile = $subscriber->bandwidth_profile ?: '20M/20M';
            $this->coaService->changeBandwidthForUser(
                username: $subscriber->username,
                rateLimit: $normalProfile,
                subscriberId: (string) $subscriber->subscriber_id
            );
        } else {
            $fup->save();
        }

        return [
            'subscriber_id' => $subscriber->subscriber_id,
            'used_bytes' => $fup->used_bytes,
            'total_allowance_bytes' => $totalAllowance,
            'percentage_used' => $totalAllowance > 0 ? round(($fup->used_bytes / $totalAllowance) * 100, 2) : 0,
            'is_throttled' => $fup->is_throttled,
            'active_rate_limit' => $fup->is_throttled ? $fup->throttle_rate_limit : ($subscriber->bandwidth_profile ?: '20M/20M'),
        ];
    }

    /**
     * Purchase and apply instant data top-up package
     */
    public function purchaseTopup(Subscriber $subscriber, int $dataBytes, float $priceBdt): DataTopup
    {
        $now = Carbon::now();

        return DB::transaction(function () use ($subscriber, $dataBytes, $priceBdt, $now) {
            $topup = DataTopup::withoutGlobalScopes()->create([
                'topup_id' => (string) Str::uuid(),
                'tenant_id' => (int) $subscriber->tenant_id,
                'subscriber_id' => (string) $subscriber->subscriber_id,
                'data_bytes' => $dataBytes,
                'price_bdt' => $priceBdt,
                'status' => 'applied',
                'created_at' => $now,
            ]);

            // General ledger entry: DR Accounts Receivable (1100), CR Topup Revenue (4000)
            $this->ledgerService->recordJournalEntry(
                tenantId: (int) $subscriber->tenant_id,
                referenceType: 'data_topup',
                referenceId: (string) $topup->topup_id,
                debitAccountCode: '1100',
                creditAccountCode: '4000',
                amountBdt: $priceBdt,
                description: sprintf('Data top-up of %d GB for %s', (int) ($dataBytes / (1024 ** 3)), $subscriber->username)
            );

            // Add bytes to current FUP cycle
            $fup = FupQuota::withoutGlobalScopes()
                ->where('subscriber_id', $subscriber->subscriber_id)
                ->where('cycle_start', '<=', $now)
                ->where('cycle_end', '>=', $now)
                ->first();

            if ($fup) {
                $fup->data_limit_bytes += $dataBytes;
                $fup->save();
            }

            // Re-evaluate throttle state
            $this->checkAndApplyFup($subscriber);

            return $topup;
        });
    }

    /**
     * Process monthly data-cap rollover on subscription renewal
     */
    public function processMonthlyRollover(Subscriber $subscriber): FupQuota
    {
        $now = Carbon::now();

        $lastQuota = FupQuota::withoutGlobalScopes()
            ->where('subscriber_id', $subscriber->subscriber_id)
            ->latest('cycle_end')
            ->first();

        $unusedBytes = 0;
        if ($lastQuota) {
            $totalAllowance = $lastQuota->data_limit_bytes + $lastQuota->rollover_bytes;
            $unusedBytes = max(0, $totalAllowance - $lastQuota->used_bytes);
        }

        // Initialize new cycle with rolled-over bytes (capped at 100% of base limit)
        $tariff = Tariff::withoutGlobalScopes()->find($subscriber->tariff_id);
        $baseBytes = 500 * (1024 ** 3); // 500 GB default
        $maxRollover = $baseBytes;
        $actualRollover = min($unusedBytes, $maxRollover);

        $newQuota = FupQuota::withoutGlobalScopes()->create([
            'quota_id' => (string) Str::uuid(),
            'tenant_id' => (int) $subscriber->tenant_id,
            'subscriber_id' => (string) $subscriber->subscriber_id,
            'cycle_start' => $now,
            'cycle_end' => $now->copy()->addDays(30),
            'data_limit_bytes' => $baseBytes,
            'used_bytes' => 0,
            'rollover_bytes' => $actualRollover,
            'throttle_rate_limit' => '2M/2M',
            'is_throttled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $newQuota;
    }

    protected function initializeQuotaCycle(Subscriber $subscriber, Carbon $now): FupQuota
    {
        return FupQuota::withoutGlobalScopes()->create([
            'quota_id' => (string) Str::uuid(),
            'tenant_id' => (int) $subscriber->tenant_id,
            'subscriber_id' => (string) $subscriber->subscriber_id,
            'cycle_start' => $now->copy()->startOfMonth(),
            'cycle_end' => $now->copy()->endOfMonth(),
            'data_limit_bytes' => 500 * (1024 ** 3), // 500 GB
            'used_bytes' => 0,
            'rollover_bytes' => 0,
            'throttle_rate_limit' => '2M/2M',
            'is_throttled' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
