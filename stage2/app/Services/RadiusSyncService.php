<?php
// File: app/Services/RadiusSyncService.php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use App\Models\Tariff;
use Illuminate\Support\Facades\DB;

class RadiusSyncService
{
    /**
     * Atomically synchronize a Subscriber's FreeRADIUS authentication (radcheck)
     * and authorization (radreply) attributes inside a PostgreSQL transaction.
     */
    public function syncSubscriber(Subscriber $subscriber, ?string $previousUsername = null): void
    {
        $subscriber->loadMissing('tariff');

        DB::transaction(function () use ($subscriber, $previousUsername): void {
            $shardId = Subscriber::computeShardId($subscriber->username);

            // 1. If the PPPoE username was changed, remove stale rows for the old username
            if ($previousUsername !== null && $previousUsername !== $subscriber->username) {
                DB::table('radcheck')->where('username', $previousUsername)->delete();
                DB::table('radreply')->where('username', $previousUsername)->delete();
            }

            // 2. Clear current radcheck and radreply rows for idempotent upsert
            DB::table('radcheck')->where('username', $subscriber->username)->delete();
            DB::table('radreply')->where('username', $subscriber->username)->delete();

            // 3. If subscriber is SUSPENDED, enforce immediate RADIUS Auth-Type := Reject
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

            // 4. Sync Cleartext-Password in radcheck
            DB::table('radcheck')->insert([
                'username'  => $subscriber->username,
                'attribute' => 'Cleartext-Password',
                'op'        => ':=',
                'value'     => $subscriber->pppoe_password,
                'shard_id'  => $shardId,
            ]);

            // 5. Resolve Mikrotik-Rate-Limit and Framed-Pool from attached Tariff
            /** @var Tariff $tariff */
            $tariff = $subscriber->tariff;

            $isExpired = $subscriber->status === SubscriberStatus::EXPIRED;

            $rateLimit = $isExpired
                ? (string) config('isp.walled_garden_rate_limit', '512k/512k')
                : $tariff->mikrotik_rate_limit;

            $framedPool = $isExpired
                ? ($tariff->walled_garden_pool ?: (string) config('isp.walled_garden_pool', 'walled_garden_pool'))
                : $tariff->framed_pool;

            $replyRows = [
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
                $replyRows[] = [
                    'username'  => $subscriber->username,
                    'attribute' => 'Framed-IP-Address',
                    'op'        => ':=',
                    'value'     => $subscriber->static_ipv4,
                    'shard_id'  => $shardId,
                ];
            }

            DB::table('radreply')->insert($replyRows);
        });
    }

    /**
     * Atomically purge all FreeRADIUS radcheck and radreply entries for a deleted subscriber.
     */
    public function deleteSubscriber(string $username): void
    {
        DB::transaction(function () use ($username): void {
            DB::table('radcheck')->where('username', $username)->delete();
            DB::table('radreply')->where('username', $username)->delete();
        });
    }
}
