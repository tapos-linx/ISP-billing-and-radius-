<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\CashCollection;
use App\Models\CashShift;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Radius\RadiusSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * P1-5: Cashier POS Retail Cash Collections & Cash Drawer Shift Management Service
 */
class CashdeskService
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected ?RadiusSyncService $radiusService = null
    ) {}

    /**
     * Open a cashier workstation cash drawer shift with float balance
     */
    public function openShift(int $tenantId, int $cashierUserId, float $startingCashBdt = 0.0): CashShift
    {
        // Check if cashier already has an active open shift
        /** @var CashShift|null $existing */
        $existing = CashShift::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('cashier_user_id', $cashierUserId)
            ->where('status', 'open')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var CashShift $shift */
        $shift = CashShift::withoutGlobalScopes()->create([
            'shift_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'cashier_user_id' => $cashierUserId,
            'opened_at' => Carbon::now(),
            'starting_cash_bdt' => $startingCashBdt,
            'collected_cash_bdt' => 0.0,
            'status' => 'open',
        ]);

        return $shift;
    }

    /**
     * Collect retail cash payment from subscriber against invoice and issue thermal POS receipt
     */
    public function collectCashPayment(string $shiftId, string $subscriberId, string $invoiceId, float $amountBdt): CashCollection
    {
        return DB::transaction(function () use ($shiftId, $subscriberId, $invoiceId, $amountBdt): CashCollection {
            /** @var CashShift $shift */
            $shift = CashShift::withoutGlobalScopes()->lockForUpdate()->findOrFail($shiftId);

            if ($shift->status !== 'open') {
                throw new RuntimeException("Cannot collect payment on a closed cash shift ({$shiftId}).");
            }

            /** @var Invoice $invoice */
            $invoice = Invoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoiceId);

            $receiptNumber = 'RCP-'.Carbon::now()->format('ymd').'-'.strtoupper(Str::random(6));

            /** @var CashCollection $collection */
            $collection = CashCollection::withoutGlobalScopes()->create([
                'collection_id' => (string) Str::uuid(),
                'shift_id' => $shift->shift_id,
                'tenant_id' => $shift->tenant_id,
                'subscriber_id' => $subscriberId,
                'invoice_id' => $invoiceId,
                'receipt_number' => $receiptNumber,
                'amount_bdt' => $amountBdt,
                'collected_at' => Carbon::now(),
            ]);

            // Update shift total
            $shift->collected_cash_bdt += $amountBdt;
            $shift->save();

            // Mark invoice paid
            $invoice->status = 'paid';
            $invoice->paid_at = Carbon::now();
            $invoice->save();

            // Create Payment record
            Payment::withoutGlobalScopes()->create([
                'payment_id' => (string) Str::uuid(),
                'tenant_id' => $shift->tenant_id,
                'subscriber_id' => $subscriberId,
                'invoice_id' => $invoiceId,
                'amount_bdt' => $amountBdt,
                'gateway' => 'cash',
                'trx_id' => $receiptNumber,
                'status' => 'completed',
                'paid_at' => Carbon::now(),
            ]);

            // Post double-entry journal: DR Cash in Drawer (1010) / CR Accounts Receivable (1100)
            $this->ledgerService->recordJournalEntry(
                tenantId: $shift->tenant_id,
                referenceType: 'cash_collection',
                referenceId: $collection->collection_id,
                debitAccountCode: '1010',
                creditAccountCode: '1100',
                amountBdt: $amountBdt,
                description: "Cashier POS Collection Receipt #{$receiptNumber}",
                subscriberId: $subscriberId,
                invoiceId: $invoiceId
            );

            // Unblock subscriber if suspended
            /** @var Subscriber|null $subscriber */
            $subscriber = Subscriber::withoutGlobalScopes()->find($subscriberId);
            if ($subscriber !== null && $subscriber->status === 'suspended') {
                $subscriber->status = 'active';
                $subscriber->save();

                if ($this->radiusService !== null) {
                    $this->radiusService->syncSubscriber([
                        'subscriber_id' => (string) $subscriber->subscriber_id,
                        'username' => (string) $subscriber->username,
                        'password' => (string) $subscriber->password,
                        'rate_limit' => '10M/10M',
                        'status' => 'active',
                    ]);
                }
            }

            return $collection;
        });
    }

    /**
     * Reconcile and close the cashier shift with drawer audit count
     */
    public function closeShift(string $shiftId, float $closingCashBdt, ?string $notes = null): CashShift
    {
        /** @var CashShift $shift */
        $shift = CashShift::withoutGlobalScopes()->findOrFail($shiftId);

        $expectedCash = $shift->starting_cash_bdt + $shift->collected_cash_bdt;
        $discrepancy = round($closingCashBdt - $expectedCash, 2);

        $shift->status = 'closed';
        $shift->closed_at = Carbon::now();
        $shift->closing_cash_bdt = $closingCashBdt;
        $shift->discrepancy_bdt = $discrepancy;
        $shift->notes = $notes;
        $shift->save();

        return $shift;
    }
}
