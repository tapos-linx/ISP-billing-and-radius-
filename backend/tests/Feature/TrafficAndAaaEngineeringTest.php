<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Subscriber;
use App\Services\Radius\MultiVendorVsaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 (P1 Batch 1): FUP Quota, Time-of-Day Boosts, Multi-Vendor VSAs, NetFlow & CGNAT Tests
 */
class TrafficAndAaaEngineeringTest extends TestCase
{
    public function test_fup_quota_check_throttle_and_topup(): void
    {
        $subId = (string) Str::uuid();
        $username = 'fup_test_sub_'.time();

        $subscriber = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => $username,
            'bandwidth_profile' => '20M/20M',
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        // Insert radacct usage exceeding 500GB (e.g., 550 GB = 590,558,003,200 bytes)
        DB::table('radacct')->insert([
            'tenant_id' => 1,
            'acctsessionid' => 'SESS-FUP-'.time(),
            'username' => $username,
            'nasipaddress' => '10.200.0.1',
            'acctstarttime' => Carbon::now()->startOfMonth(),
            'acctstoptime' => Carbon::now(),
            'acctinputoctets' => 295279001600,
            'acctoutputoctets' => 295279001600,
        ]);

        // 1. Check FUP: should trigger throttle
        $checkResponse = $this->postJson('/api/v1/traffic/fup/check', [
            'subscriber_id' => $subId,
        ]);

        $checkResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.is_throttled', true)
            ->assertJsonPath('data.active_rate_limit', '2M/2M');

        $this->assertDatabaseHas('fup_quotas', [
            'subscriber_id' => $subId,
            'is_throttled' => true,
        ]);

        // 2. Purchase 100GB Top-up
        $topupResponse = $this->postJson('/api/v1/traffic/fup/topup', [
            'subscriber_id' => $subId,
            'data_gb' => 100,
            'price_bdt' => 200.00,
        ]);

        $topupResponse->assertStatus(201)
            ->assertJsonPath('status', 'success');
        $this->assertEquals(200.00, (float) $topupResponse->json('data.price_bdt'));

        // Verify throttle removed after top-up
        $recheck = $this->postJson('/api/v1/traffic/fup/check', [
            'subscriber_id' => $subId,
        ]);

        $recheck->assertStatus(200)
            ->assertJsonPath('data.is_throttled', false)
            ->assertJsonPath('data.active_rate_limit', '20M/20M');
    }

    public function test_time_of_day_boost_evaluation(): void
    {
        $subId = (string) Str::uuid();

        $subscriber = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => 'boost_sub_'.time(),
            'bandwidth_profile' => '20M/20M',
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        // 1. Off-peak Night time (03:30 AM): should be boosted to 40M
        $nightTime = Carbon::create(2026, 11, 21, 3, 30, 0);
        $boostResp = $this->postJson('/api/v1/traffic/time-of-day/evaluate', [
            'subscriber_id' => $subId,
            'timestamp' => $nightTime->toIso8601String(),
        ]);

        $boostResp->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.boosted', true)
            ->assertJsonPath('data.boost_rate', '40M/40M')
            ->assertJsonPath('data.contention_ratio', '1:2');

        // 2. Peak Daytime on a Weekday (Wednesday 14:00 PM): normal rate (not boosted)
        $weekdayDayTime = Carbon::create(2026, 11, 18, 14, 0, 0);
        $dayResp = $this->postJson('/api/v1/traffic/time-of-day/evaluate', [
            'subscriber_id' => $subId,
            'timestamp' => $weekdayDayTime->toIso8601String(),
        ]);

        $dayResp->assertStatus(200)
            ->assertJsonPath('data.boosted', false)
            ->assertJsonPath('data.base_rate', '20M/20M');
    }

    public function test_multi_vendor_vsa_formatting_and_proxy_failover(): void
    {
        // 1. Cisco
        $ciscoResp = $this->postJson('/api/v1/traffic/vsa/format', [
            'vendor' => 'cisco',
            'rate_limit' => '20M/20M',
        ]);
        $ciscoResp->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('attributes.0.attribute', 'cisco-avpair');

        // 2. Juniper
        $juniperResp = $this->postJson('/api/v1/traffic/vsa/format', [
            'vendor' => 'juniper',
            'rate_limit' => '30M/30M',
        ]);
        $juniperResp->assertStatus(200)
            ->assertJsonPath('attributes.0.attribute', 'ERX-Virtual-Router-Name')
            ->assertJsonPath('attributes.1.attribute', 'ERX-Ingress-Policy-Name');

        // 3. Huawei
        $huaweiResp = $this->postJson('/api/v1/traffic/vsa/format', [
            'vendor' => 'huawei',
            'rate_limit' => '50M/50M',
        ]);
        $huaweiResp->assertStatus(200)
            ->assertJsonPath('attributes.0.attribute', 'Huawei-Input-Average-Rate')
            ->assertJsonPath('attributes.0.value', '50000000');

        // 4. Proxy Failover Health
        $vsaService = new MultiVendorVsaService;
        $proxyStatus = $vsaService->getActiveRadiusProxy();
        $this->assertArrayHasKey('active_proxy', $proxyStatus);
        $this->assertEquals('healthy', $proxyStatus['primary_status']);
    }

    public function test_netflow_ingestion_and_traffic_breakdown(): void
    {
        $subId = (string) Str::uuid();

        $flows = [
            [
                'tenant_id' => 1,
                'subscriber_id' => $subId,
                'src_ip' => '100.64.0.5',
                'dst_ip' => '103.100.20.1', // BDIX prefix
                'protocol' => 'TCP',
                'src_port' => 45890,
                'dst_port' => 80,
                'bytes' => 50000000,
                'packets' => 35000,
            ],
            [
                'tenant_id' => 1,
                'subscriber_id' => $subId,
                'src_ip' => '100.64.0.5',
                'dst_ip' => '142.250.180.14', // YouTube prefix
                'protocol' => 'UDP',
                'src_port' => 52100,
                'dst_port' => 443,
                'bytes' => 120000000,
                'packets' => 90000,
            ],
            [
                'tenant_id' => 1,
                'subscriber_id' => $subId,
                'src_ip' => '100.64.0.5',
                'dst_ip' => '192.168.10.1',
                'protocol' => 'UDP',
                'src_port' => 27015,
                'dst_port' => 27015, // Gaming
                'bytes' => 10000000,
                'packets' => 20000,
            ],
        ];

        // 1. Ingest
        $ingestResp = $this->postJson('/api/v1/traffic/netflow/ingest', ['flows' => $flows]);
        $ingestResp->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('ingested_flows', 3);

        // 2. Query breakdown
        $breakdownResp = $this->getJson("/api/v1/traffic/netflow/breakdown?subscriber_id={$subId}");
        $breakdownResp->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertGreaterThan(0, $breakdownResp->json('data.total_bytes'));

        $this->assertDatabaseHas('netflow_records', [
            'subscriber_id' => $subId,
            'category' => 'youtube',
        ]);
    }

    public function test_cgnat_port_block_allocation_and_correlation(): void
    {
        $subId = (string) Str::uuid();
        $username = 'cgnat_sub_'.time();
        $publicIp = '103.145.10.100';

        Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => $username,
            'bandwidth_profile' => '20M/20M',
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        // 1. Allocate port block (10000-10999)
        $allocResp = $this->postJson('/api/v1/traffic/cgnat/allocate', [
            'subscriber_id' => $subId,
            'private_ip' => '100.64.5.12',
            'public_ip' => $publicIp,
            'port_count' => 1000,
        ]);

        $allocResp->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.public_ip', $publicIp);

        $startPort = (int) $allocResp->json('data.port_start');
        $endPort = (int) $allocResp->json('data.port_end');
        $this->assertEquals($startPort + 999, $endPort);

        // 2. Correlate public IP + middle port in allocated block
        $targetPort = $startPort + 500;
        $correlateResp = $this->getJson("/api/v1/traffic/cgnat/correlate?public_ip={$publicIp}&port={$targetPort}&timestamp=".urlencode(now()->toIso8601String()));
        $correlateResp->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.subscriber_id', $subId)
            ->assertJsonPath('data.username', $username)
            ->assertJsonPath('data.private_ip', '100.64.5.12');
    }
}
