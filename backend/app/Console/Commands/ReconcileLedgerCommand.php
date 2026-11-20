<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ledger\DoubleEntryLedgerService;
use Illuminate\Console\Command;

class ReconcileLedgerCommand extends Command
{
    protected $signature = 'ledger:reconcile';

    protected $description = 'Perform audit reconciliation on all double-entry general ledger accounts';

    public function handle(DoubleEntryLedgerService $ledgerService): int
    {
        $this->info('Starting Double-Entry Ledger Reconciliation Audit...');

        $report = $ledgerService->reconcileLedger();

        $this->table(
            ['Account Code', 'Account Name', 'Type', 'Normal Balance', 'Total Debits (BDT)', 'Total Credits (BDT)'],
            array_map(function ($acc) {
                return [
                    $acc->code,
                    $acc->name,
                    $acc->type,
                    $acc->normal_balance,
                    number_format((float) $acc->total_debit, 2),
                    number_format((float) $acc->total_credit, 2),
                ];
            }, $report['trial_balance'])
        );

        $this->line('');
        $this->line('Total Global Debits:  BDT '.number_format($report['total_debit_bdt'], 2));
        $this->line('Total Global Credits: BDT '.number_format($report['total_credit_bdt'], 2));

        if ($report['is_balanced']) {
            $this->info('[PASS] Double-entry ledger is perfectly balanced (Debits == Credits).');

            return Command::SUCCESS;
        }

        $this->error("[FAIL] Ledger imbalance detected! ({$report['imbalanced_count']} imbalanced journals)");

        return Command::FAILURE;
    }
}
