<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Audit\BillingAuditService;
use App\Services\Ledger\DoubleEntryLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * P0-5: Credit Notes, Refunds & Immutable Invoice State Transition Service
 */
class InvoiceLifecycleService
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected BillingAuditService $auditService
    ) {}

    /**
     * Issue Credit Note against an issued invoice and reverse revenue in General Ledger
     */
    public function issueCreditNote(Invoice $invoice, float $amount, string $reason): CreditNote
    {
        if ($amount <= 0 || $amount > (float) $invoice->total_bdt) {
            throw new InvalidArgumentException('Credit note amount must be positive and not exceed invoice total.');
        }

        DB::beginTransaction();
        try {
            $subtotal = round($amount / 1.05, 2);
            $vat = round($amount - $subtotal, 2);
            $creditNoteNumber = 'CN-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $creditNote = CreditNote::withoutGlobalScopes()->create([
                'credit_note_number' => $creditNoteNumber,
                'tenant_id' => (int) $invoice->tenant_id,
                'invoice_id' => (string) $invoice->invoice_id,
                'subscriber_id' => (string) $invoice->subscriber_id,
                'subtotal_bdt' => $subtotal,
                'vat_bdt' => $vat,
                'total_bdt' => $amount,
                'reason' => $reason,
                'status' => 'issued',
                'created_at' => Carbon::now(),
            ]);

            // Reverse AR and Revenue in General Ledger: DR Sales Returns (4100) / CR Accounts Receivable (1100)
            $this->ledgerService->recordJournalEntry(
                tenantId: (int) $invoice->tenant_id,
                referenceType: 'credit_note_issuance',
                referenceId: (string) $creditNote->id,
                debitAccountCode: '4000', // Debit revenue to reduce sales
                creditAccountCode: '1100', // Credit AR to reduce balance owed
                amountBdt: $amount,
                description: sprintf('Credit Note %s for Invoice %s: %s', $creditNoteNumber, $invoice->invoice_id, $reason)
            );

            // If unpaid and fully credited, mark invoice as credited/void
            if ($invoice->status === 'unpaid' && $amount >= (float) $invoice->total_bdt) {
                $invoice->status = 'void';
                $invoice->save();
            }

            $this->auditService->logEvent(
                eventType: 'credit_note_issued',
                entityType: 'invoice',
                entityId: (string) $invoice->invoice_id,
                actorType: 'system',
                actorId: 'billing_service',
                prevState: ['status' => $invoice->getOriginal('status')],
                newState: [
                    'credit_note_number' => $creditNoteNumber,
                    'amount_bdt' => $amount,
                    'reason' => $reason,
                ]
            );

            DB::commit();

            return $creditNote;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Void an unpaid invoice with General Ledger reversal
     */
    public function voidInvoice(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status === 'paid') {
            throw new InvalidArgumentException('Cannot void a paid invoice. Issue a refund or credit note instead.');
        }

        if ($invoice->status === 'void') {
            return $invoice;
        }

        DB::beginTransaction();
        try {
            $invoice->status = 'void';
            $invoice->save();

            // Reverse Accounts Receivable entry
            $this->ledgerService->recordJournalEntry(
                tenantId: (int) $invoice->tenant_id,
                referenceType: 'invoice_void',
                referenceId: (string) $invoice->invoice_id,
                debitAccountCode: '4000', // Reverse revenue
                creditAccountCode: '1100', // Reverse AR
                amountBdt: (float) $invoice->total_bdt,
                description: sprintf('Voided Invoice %s: %s', $invoice->invoice_id, $reason)
            );

            $this->auditService->logEvent(
                eventType: 'invoice_voided',
                entityType: 'invoice',
                entityId: (string) $invoice->invoice_id,
                actorType: 'system',
                actorId: 'billing_service',
                prevState: ['status' => 'unpaid'],
                newState: ['status' => 'void', 'reason' => $reason, 'amount_bdt' => $invoice->total_bdt]
            );

            DB::commit();

            return $invoice;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process refund against a paid invoice / payment
     */
    public function processRefund(Payment $payment, float $amount, string $reason, string $method = 'bkash'): Refund
    {
        if ($amount <= 0 || $amount > (float) $payment->amount_bdt) {
            throw new InvalidArgumentException('Refund amount must be positive and not exceed payment amount.');
        }

        DB::beginTransaction();
        try {
            $refundNumber = 'REF-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $refund = Refund::withoutGlobalScopes()->create([
                'refund_number' => $refundNumber,
                'tenant_id' => (int) $payment->tenant_id,
                'payment_id' => $payment->payment_id,
                'invoice_id' => (string) $payment->invoice_id,
                'subscriber_id' => (string) $payment->subscriber_id,
                'amount_bdt' => $amount,
                'method' => $method,
                'gateway_refund_id' => 'GATEWAY-REF-'.strtoupper(Str::random(10)),
                'reason' => $reason,
                'status' => 'completed',
                'created_at' => Carbon::now(),
            ]);

            // General Ledger: DR Accounts Receivable (1100) / CR Cash/Bank (1010)
            $this->ledgerService->recordJournalEntry(
                tenantId: (int) $payment->tenant_id,
                referenceType: 'payment_refund',
                referenceId: (string) $refund->id,
                debitAccountCode: '1100', // Restore AR
                creditAccountCode: '1010', // Deduct Cash/Bank
                amountBdt: $amount,
                description: sprintf('Refund %s for Payment %s: %s', $refundNumber, $payment->trx_id, $reason)
            );

            $this->auditService->logEvent(
                eventType: 'payment_refunded',
                entityType: 'payment',
                entityId: (string) $payment->payment_id,
                actorType: 'system',
                actorId: 'billing_service',
                prevState: ['amount_bdt' => $payment->amount_bdt],
                newState: [
                    'refund_number' => $refundNumber,
                    'amount_bdt' => $amount,
                    'method' => $method,
                    'reason' => $reason,
                ]
            );

            DB::commit();

            return $refund;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
