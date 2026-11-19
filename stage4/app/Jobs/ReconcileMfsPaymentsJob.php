<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileMfsPaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $unreconciled = DB::table('payment_webhooks')
            ->where('status', 'verified')
            ->whereNull('reconciled_at')
            ->where('created_at', '>=', now()->subHours(24))
            ->limit(1000)
            ->get();

        foreach ($unreconciled as $tx) {
            $invoicePaid = DB::table('invoices')
                ->where('id', $tx->invoice_id)
                ->where('status', 'paid')
                ->exists();

            if (!$invoicePaid) {
                Log::error('MFS reconciliation mismatch: webhook verified but invoice unpaid', [
                    'trx_id' => $tx->trx_id,
                    'invoice_id' => $tx->invoice_id,
                ]);
                continue;
            }

            DB::table('payment_webhooks')
                ->where('id', $tx->id)
                ->update(['reconciled_at' => now()]);
        }
    }
}
