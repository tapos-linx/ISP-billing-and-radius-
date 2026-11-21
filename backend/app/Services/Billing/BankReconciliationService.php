<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Radius\RadiusSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P1-4: Automated Bank Statement CSV/OFX Reconciliation Service
 * Reconciles bank feed credits against outstanding invoices, posting double-entry
 * ledger settlements and unblocking suspended subscriber lines in RADIUS.
 */
class BankReconciliationService
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected ?RadiusSyncService $radiusService = null
    ) {}

    /**
     * Ingest bank statement feed lines
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function ingestStatement(int $tenantId, string $bankName, string $accountNumber, string $statementDate, array $lines): BankStatement
    {
        return DB::transaction(function () use ($tenantId, $bankName, $accountNumber, $statementDate, $lines): BankStatement {
            $statementId = (string) Str::uuid();
            $totalCredits = 0.0;
            $totalDebits = 0.0;

            /** @var BankStatement $statement */
            $statement = BankStatement::withoutGlobalScopes()->create([
                'statement_id' => $statementId,
                'tenant_id' => $tenantId,
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'statement_date' => Carbon::parse($statementDate)->format('Y-m-d'),
                'total_credits_bdt' => 0.0,
                'total_debits_bdt' => 0.0,
                'status' => 'uploaded',
                'created_at' => Carbon::now(),
            ]);

            foreach ($lines as $line) {
                $amount = (float) $line['amount_bdt'];
                if ($amount >= 0) {
                    $totalCredits += $amount;
                } else {
                    $totalDebits += abs($amount);
                }

                BankStatementLine::withoutGlobalScopes()->create([
                    'line_id' => (string) Str::uuid(),
                    'statement_id' => $statementId,
                    'tenant_id' => $tenantId,
                    'transaction_date' => Carbon::parse($line['date'] ?? $statementDate)->format('Y-m-d'),
                    'reference_number' => (string) ($line['reference_number'] ?? 'REF-'.Str::random(8)),
                    'description' => isset($line['description']) ? (string) $line['description'] : null,
                    'amount_bdt' => $amount,
                    'match_status' => 'pending',
                    'created_at' => Carbon::now(),
                ]);
            }

            $statement->total_credits_bdt = $totalCredits;
            $statement->total_debits_bdt = $totalDebits;
            $statement->save();

            return $statement;
        });
    }

    /**
     * Reconcile a single bank statement line against an outstanding invoice
     *
     * @return array<string, mixed>
     */
    public function reconcileLine(string $lineId, ?string $invoiceId = null): array
    {
        DB::beginTransaction();

        try {
            /** @var BankStatementLine $line */
            $line = BankStatementLine::withoutGlobalScopes()->lockForUpdate()->findOrFail($lineId);

            /** @var Invoice|null $invoice */
            $invoice = null;

            if ($invoiceId !== null) {
                $invoice = Invoice::withoutGlobalScopes()->find($invoiceId);
            } else {
                // Auto-match strategy: search by reference or amount
                $invoice = Invoice::withoutGlobalScopes()
                    ->where('tenant_id', $line->tenant_id)
                    ->where('status', 'issued')
                    ->where(function ($query) use ($line): void {
                        $query->where('invoice_id', $line->reference_number)
                            ->orWhere('amount_bdt', $line->amount_bdt)
                            ->orWhere('total_bdt', $line->amount_bdt);
                    })
                    ->first();
            }

            if ($invoice === null) {
                $line->match_status = 'unmatched';
                $line->save();
                DB::commit();

                return [
                    'reconciled' => false,
                    'line_id' => $line->line_id,
                    'reason' => 'No matching outstanding invoice found.',
                ];
            }

            // Mark invoice paid
            $invoice->status = 'paid';
            $invoice->paid_at = Carbon::now();
            $invoice->save();

            // Create Payment record
            /** @var Payment $payment */
            $payment = Payment::withoutGlobalScopes()->create([
                'payment_id' => (string) Str::uuid(),
                'tenant_id' => $line->tenant_id,
                'subscriber_id' => $invoice->subscriber_id,
                'invoice_id' => $invoice->invoice_id,
                'amount_bdt' => $line->amount_bdt,
                'gateway' => 'bank_transfer',
                'trx_id' => $line->reference_number,
                'status' => 'completed',
                'paid_at' => Carbon::now(),
            ]);

            // Post double-entry journal: DR Bank (1010) / CR Accounts Receivable (1100)
            $this->ledgerService->recordJournalEntry(
                tenantId: $line->tenant_id,
                referenceType: 'bank_statement_line',
                referenceId: $line->line_id,
                debitAccountCode: '1010',
                creditAccountCode: '1100',
                amountBdt: $line->amount_bdt,
                description: "Bank Reconciliation for Invoice #{$invoice->invoice_id}",
                subscriberId: (string) $invoice->subscriber_id,
                invoiceId: (string) $invoice->invoice_id
            );

            // Update line status
            $line->matched_invoice_id = $invoice->invoice_id;
            $line->match_status = 'reconciled';
            $line->save();

            // Unblock subscriber if suspended
            /** @var Subscriber|null $subscriber */
            $subscriber = Subscriber::withoutGlobalScopes()->find($invoice->subscriber_id);
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

            DB::commit();

            return [
                'reconciled' => true,
                'line_id' => $line->line_id,
                'invoice_id' => $invoice->invoice_id,
                'payment_id' => $payment->payment_id,
                'amount_bdt' => $line->amount_bdt,
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Run batch auto-reconciliation across all pending lines in a statement
     *
     * @return array<string, int>
     */
    public function autoReconcileStatement(string $statementId): array
    {
        $lines = BankStatementLine::withoutGlobalScopes()
            ->where('statement_id', $statementId)
            ->where('match_status', 'pending')
            ->get();

        $matchedCount = 0;
        $unmatchedCount = 0;

        foreach ($lines as $line) {
            $result = $this->reconcileLine($line->line_id);
            if ($result['reconciled']) {
                $matchedCount++;
            } else {
                $unmatchedCount++;
            }
        }

        // Check if statement is fully reconciled
        $remaining = BankStatementLine::withoutGlobalScopes()
            ->where('statement_id', $statementId)
            ->whereIn('match_status', ['pending', 'unmatched'])
            ->count();

        if ($remaining === 0) {
            BankStatement::withoutGlobalScopes()->where('statement_id', $statementId)->update(['status' => 'reconciled']);
        }

        return [
            'total_processed' => $lines->count(),
            'reconciled' => $matchedCount,
            'unmatched' => $unmatchedCount,
        ];
    }
}
