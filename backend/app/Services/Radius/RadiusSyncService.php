<?php

declare(strict_types=1);

namespace App\Services\Radius;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Stage 8 Vertical Slice 1:
 * Atomically synchronizes Citus subscriber state to FreeRADIUS (radcheck, radreply)
 * and warms the Redis 7 Fast-Path cache (<0.4ms auth lookup).
 */
final class RadiusSyncService
{
    private const CACHE_TTL_SECONDS = 300;
    private const WALLED_GARDEN_POOL = 'walled_garden_pool';
    private const ACTIVE_POOL = 'pppoe_active_pool';

    /**
     * Sync subscriber credentials, rate-limit, and pool to Citus + Redis.
     *
     * @param array{
     *   subscriber_id: string,
     *   username: string,
     *   password: string,
     *   rate_limit: string,
     *   status: string
     * } $subscriber
     */
    public function syncSubscriber(array $subscriber): void
    {
        $username = trim($subscriber['username']);
        if ($username === '') {
            throw new RuntimeException('Subscriber username cannot be empty.');
        }

        $isActive = ($subscriber['status'] ?? 'active') === 'active';
        $targetPool = $isActive ? self::ACTIVE_POOL : self::WALLED_GARDEN_POOL;

        DB::transaction(function () use ($subscriber, $username, $targetPool): void {
            $subId = $subscriber['subscriber_id'];

            // 1. Upsert Cleartext-Password in co-located radcheck (128 shards on subscriber_id)
            $updatedPwd = DB::table('radcheck')
                ->where('subscriber_id', $subId)
                ->where('username', $username)
                ->where('attribute', 'Cleartext-Password')
                ->update(['op' => ':=', 'value' => $subscriber['password']]);

            if ($updatedPwd === 0) {
                DB::table('radcheck')->insert([
                    'subscriber_id' => $subId,
                    'username'      => $username,
                    'attribute'     => 'Cleartext-Password',
                    'op'            => ':=',
                    'value'         => $subscriber['password'],
                ]);
            }

            // 2. Upsert Mikrotik-Rate-Limit in co-located radreply
            $updatedRate = DB::table('radreply')
                ->where('subscriber_id', $subId)
                ->where('username', $username)
                ->where('attribute', 'Mikrotik-Rate-Limit')
                ->update(['op' => ':=', 'value' => $subscriber['rate_limit']]);

            if ($updatedRate === 0) {
                DB::table('radreply')->insert([
                    'subscriber_id' => $subId,
                    'username'      => $username,
                    'attribute'     => 'Mikrotik-Rate-Limit',
                    'op'            => ':=',
                    'value'         => $subscriber['rate_limit'],
                ]);
            }

            // 3. Upsert Framed-Pool (active vs walled_garden_pool)
            $updatedPool = DB::table('radreply')
                ->where('subscriber_id', $subId)
                ->where('username', $username)
                ->where('attribute', 'Framed-Pool')
                ->update(['op' => ':=', 'value' => $targetPool]);

            if ($updatedPool === 0) {
                DB::table('radreply')->insert([
                    'subscriber_id' => $subId,
                    'username'      => $username,
                    'attribute'     => 'Framed-Pool',
                    'op'            => ':=',
                    'value'         => $targetPool,
                ]);
            }
        });

        // 4. Refresh Redis 7 Fast-Path cache outside DB lock
        $this->refreshFastPathCache($username, $subscriber['password'], $subscriber['rate_limit'], $targetPool);
    }

    public function refreshFastPathCache(
        string $username,
        string $password,
        string $rateLimit,
        string $framedPool
    ): void {
        try {
            $payload = json_encode([
                'password'            => $password,
                'Mikrotik-Rate-Limit' => $rateLimit,
                'Framed-Pool'         => $framedPool,
                'Acct-Interim-Interval' => 300,
            ], JSON_THROW_ON_ERROR);

            Redis::setex("radius:auth:{$username}", self::CACHE_TTL_SECONDS, $payload);
        } catch (Throwable $e) {
            // Log warning; FreeRADIUS falls back to rlm_sql (<10ms) if Redis is warming
            logger()->warning('Redis Fast-Path cache refresh failed', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
