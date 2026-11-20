<?php

declare(strict_types=1);

namespace Tests\Feature\Radius;

use App\Services\Radius\RadiusSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SubscriberRadiusSyncTest extends TestCase
{
    // use RefreshDatabase;

    public function test_active_subscriber_syncs_radcheck_radreply_and_redis(): void
    {
        Redis::shouldReceive('setex')->once()->andReturnTrue();

        $subId = (string) Str::uuid();
        $service = new RadiusSyncService;

        $service->syncSubscriber([
            'subscriber_id' => $subId,
            'username' => 'tapos_test',
            'password' => 'Tapos@2026',
            'rate_limit' => '20M/20M',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('radcheck', [
            'subscriber_id' => $subId,
            'username' => 'tapos_test',
            'attribute' => 'Cleartext-Password',
            'value' => 'Tapos@2026',
        ]);

        $this->assertDatabaseHas('radreply', [
            'subscriber_id' => $subId,
            'username' => 'tapos_test',
            'attribute' => 'Framed-Pool',
            'value' => 'pppoe_active_pool',
        ]);
    }

    public function test_expired_subscriber_switches_to_walled_garden_pool(): void
    {
        Redis::shouldReceive('setex')->once()->andReturnTrue();

        $subId = (string) Str::uuid();
        $service = new RadiusSyncService;

        $service->syncSubscriber([
            'subscriber_id' => $subId,
            'username' => 'expired_user',
            'password' => 'Pass@2026',
            'rate_limit' => '20M/20M',
            'status' => 'expired',
        ]);

        $pool = DB::table('radreply')
            ->where('subscriber_id', $subId)
            ->where('attribute', 'Framed-Pool')
            ->value('value');

        $this->assertSame('walled_garden_pool', $pool);
    }
}
