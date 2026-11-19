<?php
// File: tests/Feature/SubscriberRadiusSyncTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SubscriberStatus;
use App\Models\Nas;
use App\Models\Subscriber;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriberRadiusSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tariff $residentialTariff;
    private Tariff $corporateTariff;
    private Nas $coreBng;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coreBng = Nas::query()->create([
            'nasname'      => '10.10.10.1',
            'shortname'    => 'dhaka-ccr2216-01',
            'type'         => 'mikrotik',
            'ports'        => 3799,
            'secret'       => '${RADIUS_SECRET}',
            'description'  => 'Dhaka Core MikroTik CCR2216 BNG',
            'pop_division' => 'Dhaka',
            'is_active'    => true,
        ]);

        $this->residentialTariff = Tariff::query()->create([
            'name'                => '50Mbps Home Fiber',
            'code'                => 'HOME_50M',
            'download_mbps'       => 50,
            'upload_mbps'         => 50,
            'mikrotik_rate_limit' => '50M/50M 75M/75M 25M/25M 10/10 8 25M/25M',
            'framed_pool'         => 'pppoe_active_pool',
            'walled_garden_pool'  => 'walled_garden_pool',
            'monthly_price'       => 1200.00,
            'validity_days'       => 30,
            'is_active'           => true,
        ]);

        $this->corporateTariff = Tariff::query()->create([
            'name'                => '100Mbps Biz Dedicated',
            'code'                => 'BIZ_100M',
            'download_mbps'       => 100,
            'upload_mbps'         => 100,
            'mikrotik_rate_limit' => '100M/100M',
            'framed_pool'         => 'corp_static_pool',
            'walled_garden_pool'  => 'walled_garden_pool',
            'monthly_price'       => 3500.00,
            'validity_days'       => 30,
            'is_active'           => true,
        ]);
    }

    public function test_creating_active_subscriber_automatically_syncs_radcheck_and_radreply(): void
    {
        $subscriber = Subscriber::query()->create([
            'tariff_id'      => $this->residentialTariff->id,
            'nas_id'         => $this->coreBng->id,
            'username'       => 'tapos_carrier_01',
            'pppoe_password' => 'Pass#2026!',
            'full_name'      => 'Tapos Architect',
            'phone'          => '01711000001',
            'pop_division'   => 'Dhaka',
            'ont_serial'     => 'HWTC12345678',
            'optical_rx_dbm' => -18.40,
            'static_ipv4'    => '10.100.14.50',
            'status'         => SubscriberStatus::ACTIVE,
            'expires_at'     => now()->addDays(30),
        ]);

        $expectedShard = Subscriber::computeShardId('tapos_carrier_01');
        $this->assertSame($expectedShard, $subscriber->cgnat_shard_id);

        $this->assertDatabaseHas('radcheck', [
            'username'  => 'tapos_carrier_01',
            'attribute' => 'Cleartext-Password',
            'op'        => ':=',
            'value'     => 'Pass#2026!',
            'shard_id'  => $expectedShard,
        ]);

        $this->assertDatabaseHas('radreply', [
            'username'  => 'tapos_carrier_01',
            'attribute' => 'Mikrotik-Rate-Limit',
            'op'        => ':=',
            'value'     => '50M/50M 75M/75M 25M/25M 10/10 8 25M/25M',
        ]);

        $this->assertDatabaseHas('radreply', [
            'username'  => 'tapos_carrier_01',
            'attribute' => 'Framed-Pool',
            'op'        => ':=',
            'value'     => 'pppoe_active_pool',
        ]);

        $this->assertDatabaseHas('radreply', [
            'username'  => 'tapos_carrier_01',
            'attribute' => 'Framed-IP-Address',
            'op'        => ':=',
            'value'     => '10.100.14.50',
        ]);
    }

    public function test_updating_subscriber_to_expired_switches_radreply_to_walled_garden(): void
    {
        $subscriber = Subscriber::query()->create([
            'tariff_id'      => $this->residentialTariff->id,
            'nas_id'         => $this->coreBng->id,
            'username'       => 'mirpur_sub_88',
            'pppoe_password' => 'MirpurPass88',
            'full_name'      => 'Rahim Uddin',
            'phone'          => '01819223344',
            'pop_division'   => 'Dhaka',
            'status'         => SubscriberStatus::ACTIVE,
            'expires_at'     => now()->addDays(5),
        ]);

        $subscriber->update([
            'status'     => SubscriberStatus::EXPIRED,
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertDatabaseHas('radcheck', [
            'username'  => 'mirpur_sub_88',
            'attribute' => 'Cleartext-Password',
            'value'     => 'MirpurPass88',
        ]);

        $this->assertDatabaseHas('radreply', [
            'username'  => 'mirpur_sub_88',
            'attribute' => 'Framed-Pool',
            'op'        => ':=',
            'value'     => 'walled_garden_pool',
        ]);

        $this->assertDatabaseHas('radreply', [
            'username'  => 'mirpur_sub_88',
            'attribute' => 'Mikrotik-Rate-Limit',
            'op'        => ':=',
            'value'     => '512k/512k',
        ]);
    }

    public function test_updating_subscriber_to_suspended_enforces_auth_type_reject_and_clears_radreply(): void
    {
        $subscriber = Subscriber::query()->create([
            'tariff_id'      => $this->corporateTariff->id,
            'username'       => 'ctg_corp_09',
            'pppoe_password' => 'CorpSecret99',
            'full_name'      => 'Agrabad Logistics Ltd',
            'phone'          => '01713998877',
            'pop_division'   => 'Chittagong',
            'status'         => SubscriberStatus::ACTIVE,
            'expires_at'     => now()->addDays(20),
        ]);

        $subscriber->update([
            'status' => SubscriberStatus::SUSPENDED,
        ]);

        $this->assertDatabaseHas('radcheck', [
            'username'  => 'ctg_corp_09',
            'attribute' => 'Auth-Type',
            'op'        => ':=',
            'value'     => 'Reject',
        ]);

        $this->assertSame(
            0,
            DB::table('radreply')->where('username', 'ctg_corp_09')->count()
        );
    }

    public function test_deleting_subscriber_purges_radcheck_and_radreply_atomically(): void
    {
        $subscriber = Subscriber::query()->create([
            'tariff_id'      => $this->residentialTariff->id,
            'username'       => 'sylhet_temp_01',
            'pppoe_password' => 'TempPass123',
            'full_name'      => 'Sylhet User',
            'phone'          => '01911445566',
            'pop_division'   => 'Sylhet',
            'status'         => SubscriberStatus::ACTIVE,
            'expires_at'     => now()->addDays(15),
        ]);

        $subscriber->delete();

        $this->assertDatabaseMissing('radcheck', ['username' => 'sylhet_temp_01']);
        $this->assertDatabaseMissing('radreply', ['username' => 'sylhet_temp_01']);
    }
}

