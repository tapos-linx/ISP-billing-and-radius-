<?php
// File: app/Services/Radius/RadiusSyncService.php

declare(strict_types=1);

namespace App\Services\Radius;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use App\Models\Tariff;
use Illuminate\Support\Facades\DB;

class RadiusSyncService
{
    /**
     * Atomically synchronize a Subscriber's authentication (radcheck) and
     * authorization (radreply) attributes inside a PostgreSQL 16 transaction.
     */
    public function syncSubscriber(Subscriber $subscriber, ?string $previousUsername = null): void
    {
        $subscriber->loadMissing('tariff');

        DB::transaction(function () use ($subscriber, $previousUsername): void {
            $shardId = Subscriber::computeShardId($subscriber->username);

            // Clean up previous username rows if the PPPoE username was renamed
            if ($previousUsername !== null && $previousUsername !== $subscriber->username) {
                DB::table('radcheck')->where('username', $previousUsername)->delete();
                DB::table('radreply')->where('username', $previousUsername)->delete();
            }

            // 1. Wipe existing radcheck & radreply entries for idempotency
            DB::table('radcheck')->where('username', $subscriber->username)->delete();
            DB::table('radreply')->where('username', $subscriber->username)->delete();

            // 2. Handle SUSPENDED state -> Immediate Auth-Type := Reject
            if ($subscriber->status === SubscriberStatus::SUSPENDED) {
                DB::table('radcheck')->insert([
                    'username'  => $subscriber->username,
                    'attribute' => 'Auth-Type',
                    'op'        => ':=',
                    'value'     => 'Reject',
                    'shard_id'  => $shardId,
                ]);

                return;
            }

            // 3. Provision Cleartext-Password in radcheck for ACTIVE and EXPIRED subscribers
            DB::table('radcheck')->insert([
                'username'  => $subscriber->username,
                'attribute' => 'Cleartext-Password',
                'op'        => ':=',
                'value'     => $subscriber->pppoe_password,
                'shard_id'  => $shardId,
            ]);

            // 4. Determine rate-limit and IP pool based on SubscriberStatus
            /** @var Tariff $tariff */
            $tariff = $subscriber->tariff;

            $isExpired = $subscriber->status === SubscriberStatus::EXPIRED;
            $rateLimit = $isExpired
                ? (string) config('isp.walled_garden_rate_limit', '512k/512k')
                : $tariff->mikrotik_rate_limit;

            $framedPool = $isExpired
                ? ($tariff->walled_garden_pool ?: (string) config('isp.walled_garden_pool', 'walled_garden_pool'))
                : $tariff->framed_pool;

            $replyAttributes = [
                [
                    'username'  => $subscriber->username,
                    'attribute' => 'Mikrotik-Rate-Limit',
                    'op'        => ':=',
                    'value'     => $rateLimit,
                    'shard_id'  => $shardId,
                ],
                [
                    'username'  => $subscriber->username,
                    'attribute' => 'Framed-Pool',
                    'op'        => ':=',
                    'value'     => $framedPool,
                    'shard_id'  => $shardId,
                ],
                [
                    'username'  => $subscriber->username,
                    'attribute' => 'Acct-Interim-Interval',
                    'op'        => ':=',
                    'value'     => '300',
                    'shard_id'  => $shardId,
                ],
            ];

            if (! $isExpired && $subscriber->static_ipv4 !== null && $subscriber->static_ipv4 !== '') {
                $replyAttributes[] = [
                    'username'  => $subscriber->username,
                    'attribute' => 'Framed-IP-Address',
                    'op'        => ':=',
                    'value'     => $subscriber->static_ipv4,
                    'shard_id'  => $shardId,
                ];
            }

            DB::table('radreply')->insert($replyAttributes);
        });
    }

    /**
     * Remove all FreeRADIUS credentials and reply attributes when a subscriber is deleted.
     */
    public function purgeSubscriber(string $username): void
    {
        DB::transaction(function () use ($username): void {
            DB::table('radcheck')->where('username', $username)->delete();
            DB::table('radreply')->where('username', $username)->delete();
        });
    }

    /**
     * Bulk re-sync all active subscribers belonging to a modified Tariff.
     */
    public function syncTariffSubscribers(Tariff $tariff): void
    {
        $tariff->subscribers()
            ->where('status', SubscriberStatus::ACTIVE->value)
            ->chunkById(500, function ($subscribers): void {
                foreach ($subscribers as $subscriber) {
                    $this->syncSubscriber($subscriber);
                }
            });
    }
}
