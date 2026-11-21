<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NetworkDevice;
use App\Models\Olt;
use App\Models\Subscriber;
use App\Services\Ipam\IpamService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0-9, P0-10, P0-11, P0-12: MikroTik Sync, OLT GPON Provisioning, Polling & IPAM Tests
 */
class NetworkOperationsAndIpamTest extends TestCase
{
    public function test_mikrotik_sessions_and_minio_backup(): void
    {
        // 1. Read sessions
        $response = $this->getJson('/api/v1/network/mikrotik/sessions');
        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['pppoe_sessions', 'hotspot_sessions']]);

        // 2. Trigger backup
        $backupResponse = $this->postJson('/api/v1/network/mikrotik/backup', [
            'nas_identity' => 'CCR2004-CORE-BANANI',
        ]);

        $backupResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.success', true);
    }

    public function test_olt_autofind_and_onu_provisioning(): void
    {
        $oltId = (string) Str::uuid();
        $subId = (string) Str::uuid();

        Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => 'ftth_sub_'.time(),
            'bandwidth_profile' => '30M/30M',
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        $olt = Olt::withoutGlobalScopes()->create([
            'olt_id' => $oltId,
            'tenant_id' => 1,
            'name' => 'OLT-BANANI-HUAWEI-01',
            'vendor' => 'huawei',
            'ip_address' => '10.200.0.10',
            'status' => 'online',
            'created_at' => Carbon::now(),
        ]);

        // 1. Auto-find
        $discoverResponse = $this->getJson("/api/v1/network/olts/{$oltId}/autofind");
        $discoverResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertGreaterThan(0, $discoverResponse->json('discovered_count'));

        // 2. Provision ONU
        $serial = 'HWTC'.strtoupper(Str::random(8));
        $provisionResponse = $this->postJson("/api/v1/network/olts/{$oltId}/provision", [
            'serial_number' => $serial,
            'pon_port' => '0/1/1',
            'vlan_id' => 150,
            'subscriber_id' => $subId,
        ]);

        $provisionResponse->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('provisioning.status', 'provisioned')
            ->assertJsonPath('optical.status', 'normal');

        $this->assertDatabaseHas('onus', [
            'serial_number' => $serial,
            'status' => 'provisioned',
            'vlan_id' => 150,
        ]);
    }

    public function test_distributed_network_device_polling(): void
    {
        $devId = (string) Str::uuid();

        NetworkDevice::withoutGlobalScopes()->create([
            'device_id' => $devId,
            'tenant_id' => 1,
            'name' => 'SW-CORE-ARISTA-7050',
            'device_type' => 'switch',
            'ip_address' => '10.200.0.2',
            'status' => 'online',
            'created_at' => Carbon::now(),
        ]);

        $pollResponse = $this->getJson('/api/v1/network/devices/poll');
        $pollResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');
        $this->assertGreaterThanOrEqual(1, $pollResponse->json('summary.total'));

        $this->assertDatabaseHas('device_metrics', [
            'device_id' => $devId,
        ]);
    }

    public function test_ipam_subnet_split_and_merge(): void
    {
        $ipam = new IpamService;

        // Split /24 into two /25s
        $split = $ipam->splitSubnet('10.0.0.0/24');
        $this->assertEquals(25, $split['child_mask']);
        $this->assertEquals(126, $split['usable_hosts_per_subnet']);
        $this->assertEquals(['10.0.0.0/25', '10.0.0.128/25'], $split['subnets']);

        // Merge two /25s back to /24
        $merged = $ipam->mergeSubnets('10.0.0.0/25', '10.0.0.128/25');
        $this->assertEquals('10.0.0.0/24', $merged['parent_cidr']);
        $this->assertEquals(254, $merged['usable_hosts']);

        // IPv6 delegated prefix
        $ipv6 = $ipam->allocateIpv6DelegatedPrefix('2001:db8:1000::/48', 255);
        $this->assertEquals('2001:db8:1000:ff::/64', $ipv6);
    }

    public function test_forensic_cyber_abuse_lookup(): void
    {
        $ipam = new IpamService;
        $subId = (string) Str::uuid();
        $username = 'btrc_target_'.time();
        $targetIp = '100.64.99.55';

        Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => $username,
            'bandwidth_profile' => '20M/20M',
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        $ipam->recordIpAssignment(
            subscriberId: $subId,
            tenantId: 1,
            framedIp: $targetIp,
            ipv6Prefix: '2001:db8:1000:a1::/64',
            nasIp: '10.200.0.1',
            mac: '00:11:22:33:44:55',
            sessionId: 'SESS-'.time()
        );

        // Query via API
        $response = $this->getJson("/api/v1/network/ipam/abuse-lookup?ip={$targetIp}&timestamp=".urlencode(now()->toIso8601String()));
        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.username', $username)
            ->assertJsonPath('data.subscriber_id', $subId)
            ->assertJsonPath('data.mac_address', '00:11:22:33:44:55');
    }
}
