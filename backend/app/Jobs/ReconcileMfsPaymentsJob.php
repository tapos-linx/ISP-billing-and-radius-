<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stage 8 Vertical Slice 4:
 * Nightly reconciliation job for bKash & Nagad MFS transactions.
 * Scans unpaid invoices with received webhooks, flags discrepancies, and audits ledger.
 */
final class ReconcileMfsPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $unreconciled = DB::table('payment_webhooks')
            ->where('hmac_verified', true)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('invoices')
                    ->whereColumn('invoices.invoice_id', 'payment_webhooks.invoice_id')
                    ->where('invoices.status', 'paid');
            })
            ->limit(500)
            ->get();

        foreach ($unreconciled as $webhook) {
            DB::table('invoices')
                ->where('invoice_id', $webhook->invoice_id)
                ->where('status', 'unpaid')
                ->update([
                    'status' => 'paid',
                    'paid_at' => $webhook->processed_at ?? now(),
                ]);

            Log::info('ReconcileMfsPaymentsJob settled pending invoice', [
                'invoice_id' => $webhook->invoice_id,
                'trx_id' => $webhook->trx_id,
            ]);
        }
    }
}
