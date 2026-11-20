<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\Invoice;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class DoubleEntryLedgerService
{
    /**
     * Record invoice issuance in the general ledger:
     * DR: Accounts Receivable (Total BDT)
     * CR: Broadband Internet Service Revenue (Subtotal BDT)
     * CR: NBR VAT Output Tax Payable 5% (VAT Amount BDT)
     */
    public function recordInvoiceIssuance(Invoice $invoice): string
    {
        return DB::transaction(function () use ($invoice) {
            $arAccount = LedgerAccount::where('code', '1100')->firstOrFail();
            $revAccount = LedgerAccount::where('code', '4000')->firstOrFail();
            $vatAccount = LedgerAccount::where('code', '2200')->firstOrFail();

            $journalId = (string) Str::uuid();
            $totalBdt = (float) ($invoice->total_bdt ?: $invoice->amount_bdt);
            $vatBdt = (float) ($invoice->vat_amount_bdt ?: round($totalBdt * (5.0 / 105.0), 2));
            $subtotalBdt = (float) ($invoice->subtotal_bdt ?: ($totalBdt - $vatBdt));

            // Verify math
            if (abs($totalBdt - ($subtotalBdt + $vatBdt)) > 0.01) {
                throw new InvalidArgumentException("Invoice total ({$totalBdt}) must equal subtotal ({$subtotalBdt}) + VAT ({$vatBdt})");
            }

            // 1. DR: Accounts Receivable
            LedgerEntry::create([
                'journal_entry_id' => $journalId,
                'account_id'       => $arAccount->id,
                'invoice_id'       => $invoice->invoice_id,
                'subscriber_id'    => $invoice->subscriber_id,
                'tenant_id'        => $invoice->tenant_id ?: 1,
                'debit_bdt'        => $totalBdt,
                'credit_bdt'       => 0.00,
                'description'      => "Invoice #{$invoice->invoice_id} issuance to subscriber",
                'posted_at'        => now(),
            ]);

            // 2. CR: Revenue
            LedgerEntry::create([
                'journal_entry_id' => $journalId,
                'account_id'       => $revAccount->id,
                'invoice_id'       => $invoice->invoice_id,
                'subscriber_id'    => $invoice->subscriber_id,
                'tenant_id'        => $invoice->tenant_id ?: 1,
                'debit_bdt'        => 0.00,
                'credit_bdt'       => $subtotalBdt,
                'description'      => "Broadband subscription revenue for invoice #{$invoice->invoice_id}",
                'posted_at'        => now(),
            ]);

            // 3. CR: VAT Output Tax
            if ($vatBdt > 0) {
                LedgerEntry::create([
                    'journal_entry_id' => $journalId,
                    'account_id'       => $vatAccount->id,
                    'invoice_id'       => $invoice->invoice_id,
                    'subscriber_id'    => $invoice->subscriber_id,
                    'tenant_id'        => $invoice->tenant_id ?: 1,
                    'debit_bdt'        => 0.00,
                    'credit_bdt'       => $vatBdt,
                    'description'      => "NBR 5% Mushak-6.3 VAT on invoice #{$invoice->invoice_id}",
                    'posted_at'        => now(),
                ]);
            }

            $this->validateJournalBalance($journalId);

            return $journalId;
        });
    }

    /**
     * Record payment settlement in the general ledger:
     * DR: Cash / MFS Clearing (Amount BDT)
     * CR: Accounts Receivable (Amount BDT)
     */
    public function recordPaymentSettlement(Invoice $invoice, Payment $payment): string
    {
        return DB::transaction(function () use ($invoice, $payment) {
            $cashAccount = LedgerAccount::where('code', '1010')->firstOrFail();
            $arAccount = LedgerAccount::where('code', '1100')->firstOrFail();

            $journalId = (string) Str::uuid();
            $amountBdt = (float) $payment->amount_bdt;

            if ($amountBdt <= 0) {
                throw new InvalidArgumentException("Payment amount must be greater than zero.");
            }

            // 1. DR: Cash / MFS Clearing
            LedgerEntry::create([
                'journal_entry_id' => $journalId,
                'account_id'       => $cashAccount->id,
                'invoice_id'       => $invoice->invoice_id,
                'payment_id'       => $payment->payment_id,
                'subscriber_id'    => $invoice->subscriber_id,
                'tenant_id'        => $invoice->tenant_id ?: 1,
                'debit_bdt'        => $amountBdt,
                'credit_bdt'       => 0.00,
                'description'      => "MFS settlement ({$payment->gateway} - {$payment->trx_id}) for invoice #{$invoice->invoice_id}",
                'posted_at'        => $payment->paid_at ?: now(),
            ]);

            // 2. CR: Accounts Receivable
            LedgerEntry::create([
                'journal_entry_id' => $journalId,
                'account_id'       => $arAccount->id,
                'invoice_id'       => $invoice->invoice_id,
                'payment_id'       => $payment->payment_id,
                'subscriber_id'    => $invoice->subscriber_id,
                'tenant_id'        => $invoice->tenant_id ?: 1,
                'debit_bdt'        => 0.00,
                'credit_bdt'       => $amountBdt,
                'description'      => "Relieve Accounts Receivable for settled invoice #{$invoice->invoice_id}",
                'posted_at'        => $payment->paid_at ?: now(),
            ]);

            $this->validateJournalBalance($journalId);

            return $journalId;
        });
    }

    /**
     * Ensure total debits equal total credits for a given journal entry.
     */
    private function validateJournalBalance(string $journalId): void
    {
        $sums = DB::table('ledger_entries')
            ->where('journal_entry_id', $journalId)
            ->selectRaw('SUM(debit_bdt) as total_debit, SUM(credit_bdt) as total_credit')
            ->first();

        $debit = round((float) ($sums->total_debit ?? 0), 2);
        $credit = round((float) ($sums->total_credit ?? 0), 2);

        if (abs($debit - $credit) > 0.01) {
            throw new RuntimeException("Double-entry balance check failed for journal {$journalId}: Debits ({$debit}) != Credits ({$credit})");
        }
    }

    /**
     * Comprehensive Ledger Reconciliation Audit:
     * 1. Checks that every journal entry in the ledger balances.
     * 2. Checks global total debits == total credits.
     * 3. Calculates Trial Balance by account code.
     */
    public function reconcileLedger(): array
    {
        // 1. Check for any imbalanced journals
        $imbalanced = DB::table('ledger_entries')
            ->select('journal_entry_id')
            ->selectRaw('SUM(debit_bdt) as total_debit, SUM(credit_bdt) as total_credit, ABS(SUM(debit_bdt) - SUM(credit_bdt)) as diff')
            ->groupBy('journal_entry_id')
            ->havingRaw('ABS(SUM(debit_bdt) - SUM(credit_bdt)) > 0.01')
            ->get();

        // 2. Global totals
        $global = DB::table('ledger_entries')
            ->selectRaw('SUM(debit_bdt) as global_debit, SUM(credit_bdt) as global_credit')
            ->first();

        $globalDebit = round((float) ($global->global_debit ?? 0), 2);
        $globalCredit = round((float) ($global->global_credit ?? 0), 2);
        $isBalanced = ($imbalanced->isEmpty()) && (abs($globalDebit - $globalCredit) <= 0.01);

        // 3. Trial Balance by Account
        $trialBalance = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_entries.account_id', '=', 'ledger_accounts.id')
            ->select('ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type', 'ledger_accounts.normal_balance')
            ->selectRaw('SUM(ledger_entries.debit_bdt) as total_debit, SUM(ledger_entries.credit_bdt) as total_credit')
            ->groupBy('ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.type', 'ledger_accounts.normal_balance')
            ->orderBy('ledger_accounts.code')
            ->get();

        return [
            'is_balanced'        => $isBalanced,
            'total_debit_bdt'    => $globalDebit,
            'total_credit_bdt'   => $globalCredit,
            'imbalanced_count'   => $imbalanced->count(),
            'imbalanced_entries' => $imbalanced->toArray(),
            'trial_balance'      => $trialBalance->toArray(),
        ];
    }
}
