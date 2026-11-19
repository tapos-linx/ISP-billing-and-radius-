<?php

namespace App\Jobs;

use App\Services\Radius\RadiusCoaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ExpireOverdueSubscribersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 90];

    public function handle(RadiusCoaService $coa): void
    {
        $overdue = DB::table('subscribers')
            ->where('status', 'active')
            ->where('expires_at', '<', now())
            ->limit(500)
            ->get();

        foreach ($overdue as $sub) {
            DB::transaction(function () use ($sub) {
                DB::table('subscribers')
                    ->where('subscriber_id', $sub->subscriber_id)
                    ->update(['status' => 'expired', 'updated_at' => now()]);

                DB::table('radreply')
                    ->where('subscriber_id', $sub->subscriber_id)
                    ->where('attribute', 'Mikrotik-Rate-Limit')
                    ->update(['value' => '128k/128k']);
            });

            if (!empty($sub->nas_ip)) {
                $ok = $coa->sendCoaOrPod($sub->nas_ip, config('radius.secret'), $sub->username, 'disconnect');
                if (!$ok) {
                    $coa->recordDeadLetter($sub->subscriber_id, $sub->username, $sub->nas_ip, 'disconnect', 'CoA timeout after retries');
                }
            }
        }
    }
}
