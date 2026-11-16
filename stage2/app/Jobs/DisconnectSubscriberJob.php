<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use App\Services\RadiusCoaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CP-03.M4: Queued Horizon Worker Job for Expired Subscriber Disconnection.
 *
 * 1. Transitions the Subscriber's status to `SubscriberStatus::Expired` ('expired')
 *    inside a DB transaction (which automatically triggers SubscriberObserver ->
 *    RadiusSyncService to rewrite FreeRADIUS `radreply` to `walled_garden_pool`).
 * 2. Invokes `RadiusCoaService::sendPacketOfDisconnect()` to transmit an RFC 5176
 *    Packet of Disconnect (PoD) to the MikroTik RouterOS v7 NAS on UDP port 3799.
 */
class DisconnectSubscriberJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum attempts on transient NAS / network failure.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying a failed PoD transmission.
     *
     * @var array<int, int>
     */
    public array $backoff = [5, 15, 30];

    /**
     * Uniqueness lock window (seconds) so duplicate jobs are not queued for the same subscriber.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly Subscriber $subscriber,
    ) {
        $this->onQueue('coa-disconnects');
    }

    /**
     * Unique job identifier per subscriber ID.
     */
    public function uniqueId(): string
    {
        return 'disconnect-subscriber-' . (string) $this->subscriber->id;
    }

    /**
     * Execute the queued job via Laravel Horizon.
     */
    public function handle(RadiusCoaService $coaService): void
    {
        $subscriber = $this->subscriber->fresh(['nas', 'tariff']);

        if ($subscriber === null) {
            return;
        }

        // Step 1: Atomically transition subscriber status to Expired ('expired').
        // Because Subscriber has #[ObservedBy([SubscriberObserver::class])],
        // this update automatically triggers RadiusSyncService::syncSubscriber()
        // to update `radreply` (`Framed-Pool := walled_garden_pool`, `Mikrotik-Rate-Limit := 512k/512k`).
        DB::transaction(function () use ($subscriber): void {
            if ($subscriber->status !== SubscriberStatus::Expired) {
                $subscriber->update([
                    'status' => SubscriberStatus::Expired,
                ]);
            }
        });

        // Step 2: Send RFC 5176 Packet of Disconnect (PoD) to the MikroTik RouterOS v7 NAS on UDP 3799
        // so the active PPPoE session terminates and immediately re-authenticates into walled_garden_pool.
        $podResponse = $coaService->sendPacketOfDisconnect($subscriber);

        Log::info('DisconnectSubscriberJob completed for expired subscriber', [
            'subscriber_id' => $subscriber->id,
            'username'      => $subscriber->username,
            'status'        => $subscriber->status->value,
            'nas_ip'        => $podResponse['nas_ip'],
            'coa_port'      => $podResponse['port'],
            'pod_success'   => $podResponse['success'],
            'pod_method'    => $podResponse['method'],
        ]);
    }
}
