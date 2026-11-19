<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Radius\RadiusCoaService;
use App\Services\Radius\RadiusSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Stage 8 Vertical Slice 4:
 * Triggered daily at 00:01 AM Asia/Dhaka via Cloud Scheduler (/api/v1/jobs/expire-overdue).
 * Moves overdue subscribers to walled_garden_pool and sends UDP 3799 PoD kick.
 */
final class ExpireOverdueSubscribersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RadiusSyncService $radiusSync, RadiusCoaService $radiusCoa): void
    {
        $overdue = DB::table('subscribers')
            ->where('status', 'active')
            ->where('expires_at', '<', now())
            ->limit(1000)
            ->get();

        $nas = DB::table('nas')->first();

        foreach ($overdue as $sub) {
            DB::table('subscribers')
                ->where('subscriber_id', $sub->subscriber_id)
                ->update(['status' => 'expired']);

            $password = (string) DB::table('radcheck')
                ->where('subscriber_id', $sub->subscriber_id)
                ->where('attribute', 'Cleartext-Password')
                ->value('value');

            $radiusSync->syncSubscriber([
                'subscriber_id' => $sub->subscriber_id,
                'username'      => $sub->username,
                'password'      => $password,
                'rate_limit'    => $sub->bandwidth_profile ?? '20M/20M',
                'status'        => 'expired',
            ]);

            if ($nas) {
                try {
                    $radiusCoa->disconnectSession(
                        $sub->username,
                        $nas->nasname,
                        $nas->secret,
                        null,
                        (int) $nas->coa_port
                    );
                } catch (\Throwable $e) {
                    logger()->warning('PoD kick failed during expiry', [
                        'username' => $sub->username,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
