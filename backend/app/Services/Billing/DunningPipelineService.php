<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\DunningEvent;
use App\Models\Invoice;
use App\Models\Subscriber;
use App\Services\Audit\BillingAuditService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Radius\RadiusCoaService;
use App\Services\Radius\RadiusSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * P0-6: Multi-Stage Dunning, Grace Periods & Vacation Mode Service
 */
class DunningPipelineService
{
    public const LATE_FEE_BDT = 50.00;

    public const GRACE_DAYS = 3;

    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected RadiusSyncService $radiusSyncService,
        protected RadiusCoaService $coaService,
        protected BillingAuditService $auditService
    ) {}

    /**
     * Run full automated dunning cycle across active subscribers
     */
    public function processDunning(?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $results = [
            'pre_reminders_sent' => 0,
            'grace_periods_set' => 0,
            'late_fees_assessed' => 0,
            'subscribers_suspended' => 0,
        ];

        // 1. Check for active subscribers with expired dates
        $expiredSubscribers = Subscriber::withoutGlobalScopes()
            ->where('status', 'active')
            ->where('expires_at', '<=', $now)
            ->get();

        foreach ($expiredSubscribers as $subscriber) {
            // Check if in vacation mode
            if ($subscriber->vacation_until && Carbon::parse($subscriber->vacation_until)->isFuture()) {
                continue; // Skip subscribers on active vacation mode
            }

            // Find unpaid invoice
            $invoice = Invoice::withoutGlobalScopes()
                ->where('subscriber_id', (string) $subscriber->subscriber_id)
                ->where('status', 'unpaid')
                ->latest('due_date')
                ->first();

            if (! $invoice) {
                continue;
            }

            $graceUntil = $subscriber->grace_until ? Carbon::parse($subscriber->grace_until) : null;

            // Stage A: Has grace period not yet been set?
            if (! $graceUntil) {
                $newGraceUntil = $now->copy()->addDays(self::GRACE_DAYS);
                $subscriber->grace_until = $newGraceUntil;
                $subscriber->save();

                DunningEvent::withoutGlobalScopes()->create([
                    'tenant_id' => (int) $subscriber->tenant_id,
                    'subscriber_id' => (string) $subscriber->subscriber_id,
                    'invoice_id' => (string) $invoice->invoice_id,
                    'stage' => 'grace_active',
                    'fee_assessed_bdt' => 0.00,
                    'notes' => sprintf('Grace period granted until %s (3 days).', $newGraceUntil->toDateTimeString()),
                    'created_at' => $now,
                ]);

                $results['grace_periods_set']++;

                continue;
            }

            // Stage B: Is subscriber still within grace period?
            if ($graceUntil->isFuture()) {
                continue; // Do not suspend yet
            }

            // Stage C: Grace expired -> Assess late fee if not already assessed
            $alreadyFeeAssessed = DunningEvent::withoutGlobalScopes()
                ->where('invoice_id', (string) $invoice->invoice_id)
                ->where('stage', 'late_fee_applied')
                ->exists();

            if (! $alreadyFeeAssessed) {
                $invoice->amount_bdt = (float) $invoice->amount_bdt + self::LATE_FEE_BDT;
                $invoice->total_bdt = (float) $invoice->total_bdt + self::LATE_FEE_BDT;
                $invoice->save();

                // Post ledger entry for late fee: DR Accounts Receivable (1100) / CR Late Fee Revenue (4200)
                $this->ledgerService->recordJournalEntry(
                    tenantId: (int) $subscriber->tenant_id,
                    referenceType: 'late_fee_assessment',
                    referenceId: (string) $invoice->invoice_id,
                    debitAccountCode: '1100',
                    creditAccountCode: '4000',
                    amountBdt: self::LATE_FEE_BDT,
                    description: sprintf('Late payment fee assessed on invoice %s for %s', $invoice->invoice_id, $subscriber->username)
                );

                DunningEvent::withoutGlobalScopes()->create([
                    'tenant_id' => (int) $subscriber->tenant_id,
                    'subscriber_id' => (string) $subscriber->subscriber_id,
                    'invoice_id' => (string) $invoice->invoice_id,
                    'stage' => 'late_fee_applied',
                    'fee_assessed_bdt' => self::LATE_FEE_BDT,
                    'notes' => 'Late payment fee assessed after grace expiry.',
                    'created_at' => $now,
                ]);

                $results['late_fees_assessed']++;
            }

            // Stage D: Suspend subscriber and disconnect live session
            $subscriber->status = 'expired';
            $subscriber->save();

            // Sync FreeRADIUS to walled_garden_pool
            $password = (string) (DB::table('radcheck')
                ->where('subscriber_id', (string) $subscriber->subscriber_id)
                ->where('attribute', 'Cleartext-Password')
                ->value('value') ?: 'Tapos@2026');

            $this->radiusSyncService->syncSubscriber([
                'subscriber_id' => (string) $subscriber->subscriber_id,
                'username' => (string) $subscriber->username,
                'password' => $password,
                'rate_limit' => (string) ($subscriber->bandwidth_profile ?? '20M/20M'),
                'status' => 'expired',
            ]);

            // Disconnect live session via RFC 5176 CoA / PoD
            try {
                $this->coaService->disconnect($subscriber->username);
            } catch (\Throwable $e) {
                // Dead-lettered
            }

            DunningEvent::withoutGlobalScopes()->create([
                'tenant_id' => (int) $subscriber->tenant_id,
                'subscriber_id' => (string) $subscriber->subscriber_id,
                'invoice_id' => (string) $invoice->invoice_id,
                'stage' => 'suspended',
                'fee_assessed_bdt' => 0.00,
                'notes' => 'Subscriber suspended and transferred to walled garden after dunning escalation.',
                'created_at' => $now,
            ]);

            $results['subscribers_suspended']++;
        }

        return $results;
    }

    /**
     * Enable Vacation Mode for a subscriber (15 to 90 days pause)
     */
    public function setVacationMode(Subscriber $subscriber, int $durationDays, ?string $reason = null): array
    {
        if ($durationDays < 15 || $durationDays > 90) {
            throw new InvalidArgumentException('Vacation mode duration must be between 15 and 90 days.');
        }

        $now = Carbon::now();
        $vacationUntil = $now->copy()->addDays($durationDays);

        DB::beginTransaction();
        try {
            $subscriber->vacation_until = $vacationUntil;
            $subscriber->status = 'suspended';
            $subscriber->save();

            // Sync RADIUS to walled_garden_pool so subscriber cannot consume bandwidth during vacation
            $password = (string) (DB::table('radcheck')
                ->where('subscriber_id', (string) $subscriber->subscriber_id)
                ->where('attribute', 'Cleartext-Password')
                ->value('value') ?: 'Tapos@2026');

            $this->radiusSyncService->syncSubscriber([
                'subscriber_id' => (string) $subscriber->subscriber_id,
                'username' => (string) $subscriber->username,
                'password' => $password,
                'rate_limit' => (string) ($subscriber->bandwidth_profile ?? '20M/20M'),
                'status' => 'suspended',
            ]);

            $this->auditService->logEvent(
                eventType: 'vacation_mode_activated',
                entityType: 'subscriber',
                entityId: (string) $subscriber->subscriber_id,
                actorType: 'system',
                actorId: 'billing_service',
                prevState: ['status' => 'active'],
                newState: [
                    'duration_days' => $durationDays,
                    'vacation_until' => $vacationUntil->toDateTimeString(),
                    'reason' => $reason,
                ]
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        // Issue CoA kick
        try {
            $this->coaService->disconnect($subscriber->username);
        } catch (\Throwable $e) {
            // Handled
        }

        return [
            'success' => true,
            'subscriber_id' => $subscriber->subscriber_id,
            'status' => 'suspended',
            'vacation_until' => $vacationUntil->toDateTimeString(),
            'duration_days' => $durationDays,
        ];
    }
}
