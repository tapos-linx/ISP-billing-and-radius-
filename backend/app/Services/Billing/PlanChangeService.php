<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Services\Audit\BillingAuditService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Radius\RadiusCoaService;
use App\Services\Radius\RadiusSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P0-4: Automated Pro-Rata Billing & Mid-Cycle Plan Change Service
 */
class PlanChangeService
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected RadiusSyncService $radiusSyncService,
        protected RadiusCoaService $coaService,
        protected BillingAuditService $auditService
    ) {}

    /**
     * Calculate exact daily pro-rata charges and credits for mid-cycle plan switch
     */
    public function calculateProRata(Subscriber $subscriber, Tariff $newTariff): array
    {
        $currentTariff = Tariff::withoutGlobalScopes()->find($subscriber->tariff_id);
        $now = Carbon::now();
        $expiresAt = $subscriber->expires_at ? Carbon::parse($subscriber->expires_at) : $now->copy()->addDays(30);

        $totalCycleDays = 30;
        $remainingDays = max(0, $now->diffInDays($expiresAt, false));

        $currentPrice = $currentTariff ? (float) $currentTariff->price_bdt : 0.00;
        $newPrice = (float) $newTariff->price_bdt;

        // Daily rates
        $currentDailyRate = $currentPrice / $totalCycleDays;
        $newDailyRate = $newPrice / $totalCycleDays;

        $unusedCredit = round($currentDailyRate * $remainingDays, 2);
        $newProRataCharge = round($newDailyRate * $remainingDays, 2);
        $netAdjustment = round($newProRataCharge - $unusedCredit, 2);

        return [
            'subscriber_id' => $subscriber->subscriber_id,
            'current_tariff' => $currentTariff ? $currentTariff->name : 'N/A',
            'current_price_bdt' => $currentPrice,
            'new_tariff' => $newTariff->name,
            'new_price_bdt' => $newPrice,
            'total_cycle_days' => $totalCycleDays,
            'remaining_days' => $remainingDays,
            'unused_credit_bdt' => $unusedCredit,
            'new_charge_bdt' => $newProRataCharge,
            'net_adjustment_bdt' => $netAdjustment,
            'is_upgrade' => ($netAdjustment > 0),
        ];
    }

    /**
     * Apply plan change, update RADIUS, trigger CoA bandwidth switch, and post ledger adjustments
     */
    public function applyPlanChange(Subscriber $subscriber, int $newTariffId): array
    {
        $newTariff = Tariff::withoutGlobalScopes()->findOrFail($newTariffId);
        $calc = $this->calculateProRata($subscriber, $newTariff);
        $netAdjustment = $calc['net_adjustment_bdt'];

        DB::beginTransaction();
        try {
            // 1. Post ledger adjustments if there is a price differential
            if ($netAdjustment > 0) {
                // Upgrade: Debit AR, Credit Revenue
                $this->ledgerService->recordJournalEntry(
                    tenantId: (int) $subscriber->tenant_id,
                    referenceType: 'plan_upgrade_prorata',
                    referenceId: (string) $subscriber->subscriber_id,
                    debitAccountCode: '1100', // Accounts Receivable
                    creditAccountCode: '4000', // Subscription Revenue
                    amountBdt: $netAdjustment,
                    description: sprintf('Mid-cycle upgrade pro-rata for %s to %s', $subscriber->username, $newTariff->name)
                );

                // Create pro-rata invoice
                Invoice::withoutGlobalScopes()->create([
                    'invoice_id' => (string) Str::uuid(),
                    'tenant_id' => (int) $subscriber->tenant_id,
                    'subscriber_id' => (string) $subscriber->subscriber_id,
                    'amount_bdt' => $netAdjustment,
                    'subtotal_bdt' => round($netAdjustment / 1.05, 2),
                    'vat_rate_percent' => 5.00,
                    'vat_amount_bdt' => round($netAdjustment - ($netAdjustment / 1.05), 2),
                    'total_bdt' => $netAdjustment,
                    'status' => 'unpaid',
                    'due_date' => Carbon::now()->addDays(3),
                    'mushak_6_3_number' => 'MUSHAK-PRORATA-'.strtoupper(Str::random(8)),
                    'created_at' => Carbon::now(),
                ]);
            } elseif ($netAdjustment < 0) {
                // Downgrade: Credit to subscriber's pro-rata balance
                $creditAmount = abs($netAdjustment);
                $subscriber->pro_rata_balance = (float) ($subscriber->pro_rata_balance ?? 0) + $creditAmount;
            }

            // 2. Update subscriber tariff and bandwidth profile
            $oldProfile = $subscriber->bandwidth_profile;
            $subscriber->tariff_id = $newTariff->tariff_id;
            $subscriber->bandwidth_profile = $newTariff->rate_limit;
            $subscriber->save();

            // 3. Sync FreeRADIUS radreply
            $password = (string) (DB::table('radcheck')
                ->where('subscriber_id', (string) $subscriber->subscriber_id)
                ->where('attribute', 'Cleartext-Password')
                ->value('value') ?: 'Tapos@2026');

            $this->radiusSyncService->syncSubscriber([
                'subscriber_id' => (string) $subscriber->subscriber_id,
                'username' => (string) $subscriber->username,
                'password' => $password,
                'rate_limit' => (string) ($newTariff->rate_limit ?? '20M/20M'),
                'status' => (string) $subscriber->status,
            ]);

            // 4. Audit Log
            $this->auditService->logEvent(
                eventType: 'mid_cycle_plan_change',
                entityType: 'subscriber',
                entityId: (string) $subscriber->subscriber_id,
                actorType: 'system',
                actorId: 'billing_service',
                prevState: ['bandwidth_profile' => $oldProfile],
                newState: [
                    'old_tariff_profile' => $oldProfile,
                    'new_tariff_profile' => $newTariff->rate_limit,
                    'net_adjustment_bdt' => $netAdjustment,
                ]
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // 5. Trigger RFC 5176 CoA rate change on live BNG (<500ms SLA)
        $coaResult = false;
        try {
            $coaResult = $this->coaService->changeBandwidthForUser(
                username: $subscriber->username,
                rateLimit: $newTariff->rate_limit,
                subscriberId: (string) $subscriber->subscriber_id
            );
        } catch (\Throwable $e) {
            // Logged as failed CoA job inside RadiusCoaService
        }

        return [
            'success' => true,
            'subscriber_id' => $subscriber->subscriber_id,
            'new_tariff' => $newTariff->name,
            'new_rate_limit' => $newTariff->rate_limit,
            'net_adjustment_bdt' => $netAdjustment,
            'coa_dispatched' => $coaResult,
        ];
    }
}
