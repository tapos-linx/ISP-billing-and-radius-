<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FieldTechnician;
use App\Models\InventoryItem;
use App\Models\InventoryWarehouse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 (P2 Batch 2): Network Intelligence, BGP/IXP, DNS Sinkhole, OTDR & AI Forecasting Feature Test
 */
class NetworkAndFieldIntelligenceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_bgp_peering_registration_telemetry_and_ixp_ratio_analysis(): void
    {
        // 1. Register Local IXP Peer (e.g. BDIX - Bangladesh Internet Exchange)
        $bdixResp = $this->postJson('/api/v1/network/bgp/peers', [
            'tenant_id' => 1,
            'peer_name' => 'BDIX National Exchange Peering',
            'peer_type' => 'ixp',
            'remote_asn' => 24115,
            'remote_ip' => '103.145.10.1',
            'local_ip' => '103.145.10.2',
            'prefixes_received' => 1500,
        ]);
        $bdixResp->assertStatus(201);
        $bdixPeerId = $bdixResp->json('data.peer_id');
        $this->assertNotNull($bdixPeerId);

        // 2. Register Upstream Transit Peer (e.g. NTT Tier-1 IP Transit)
        $transitResp = $this->postJson('/api/v1/network/bgp/peers', [
            'tenant_id' => 1,
            'peer_name' => 'NTT Tier-1 Global Transit',
            'peer_type' => 'transit',
            'remote_asn' => 2914,
            'remote_ip' => '129.250.0.1',
            'local_ip' => '129.250.0.2',
            'prefixes_received' => 950000,
        ]);
        $transitResp->assertStatus(201);
        $transitPeerId = $transitResp->json('data.peer_id');
        $this->assertNotNull($transitPeerId);

        // 3. Record Telemetry: BDIX 7 Gbps (7,000,000,000 bps) vs Transit 3 Gbps (3,000,000,000 bps)
        $this->postJson("/api/v1/network/bgp/peers/{$bdixPeerId}/telemetry", [
            'in_bps' => 4000000000,
            'out_bps' => 3000000000,
            'uptime_seconds' => 86400,
            'flaps_count' => 0,
        ])->assertStatus(201);

        $this->postJson("/api/v1/network/bgp/peers/{$transitPeerId}/telemetry", [
            'in_bps' => 2000000000,
            'out_bps' => 1000000000,
            'uptime_seconds' => 86400,
            'flaps_count' => 0,
        ])->assertStatus(201);

        // 4. Query Peering Ratio Analytics (Total 10 Gbps, IXP 70% -> Optimal)
        $ratioResp = $this->getJson('/api/v1/network/bgp/ratios?tenant_id=1');
        $ratioResp->assertStatus(200);

        $data = $ratioResp->json('data');
        $this->assertGreaterThanOrEqual(2, $data['total_peers']);
        $this->assertEquals(7000000000, $data['ixp_traffic_bps']);
        $this->assertEquals(3000000000, $data['transit_traffic_bps']);
        $this->assertEquals(70.0, $data['ixp_ratio_pct']);
        $this->assertEquals('optimal', $data['cost_optimization_status']);
    }

    public function test_dns_sinkholing_regulatory_filtering_and_compliance(): void
    {
        // 1. Add BTRC Mandatory Regulatory Block Rule
        $domain = 'illicit-betting-'.Str::lower(Str::random(6)).'.com';
        $ruleResp = $this->postJson('/api/v1/network/dns/rules', [
            'tenant_id' => 1,
            'domain' => $domain,
            'category' => 'btrc_mandatory_gambling',
            'sinkhole_ip' => '10.254.254.254',
        ]);
        $ruleResp->assertStatus(201);
        $this->assertEquals($domain, $ruleResp->json('data.domain'));

        // 2. Evaluate DNS Query for Blocked Domain (Should be sinkholed & logged)
        $evalRespBlocked = $this->postJson('/api/v1/network/dns/evaluate', [
            'tenant_id' => 1,
            'domain' => "m.{$domain}", // Subdomain test
            'client_ip' => '100.64.12.88',
        ]);
        $evalRespBlocked->assertStatus(200);
        $this->assertTrue($evalRespBlocked->json('data.blocked'));
        $this->assertEquals('sinkhole_intercepted', $evalRespBlocked->json('data.action'));
        $this->assertEquals('10.254.254.254', $evalRespBlocked->json('data.sinkhole_ip'));

        // 3. Evaluate DNS Query for Clean Domain (Should be forwarded)
        $evalRespClean = $this->postJson('/api/v1/network/dns/evaluate', [
            'tenant_id' => 1,
            'domain' => 'google.com',
            'client_ip' => '100.64.12.88',
        ]);
        $evalRespClean->assertStatus(200);
        $this->assertFalse($evalRespClean->json('data.blocked'));
        $this->assertEquals('forward_authoritative', $evalRespClean->json('data.action'));

        // 4. Query Compliance Summary
        $compResp = $this->getJson('/api/v1/network/dns/compliance?tenant_id=1');
        $compResp->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $compResp->json('data.active_blocklist_domains'));
        $this->assertGreaterThanOrEqual(1, $compResp->json('data.total_intercepted_queries'));
    }

    public function test_otdr_distance_fault_localization_physics_engine(): void
    {
        // 1. Simulate OTDR reflection delay:
        // Round trip time = 14,000 ns (14 microseconds)
        // Refractive index n = 1.4682
        // Distance = (299,792,458 * 14e-6) / (2 * 1.4682) = ~1429.27 meters
        // Loss = 22.5 dB (Catastrophic attenuation -> fiber_break)
        $otdrResp = $this->postJson('/api/v1/network/otdr/analyze', [
            'tenant_id' => 1,
            'circuit_or_port_ref' => 'PON-OLT-BANANI-01/SLOT2/PORT4',
            'round_trip_time_ns' => 14000.0,
            'fiber_refractive_index' => 1.4682,
            'optical_loss_db' => 22.5,
            'estimated_location_address' => 'Banani Bridge Crossing near Road 11',
        ]);
        $otdrResp->assertStatus(201);

        $data = $otdrResp->json('data');
        $this->assertNotNull($data['trace_id']);
        $this->assertEquals(1429.33, $data['fault_distance_meters']);
        $this->assertEquals('fiber_break', $data['fault_type']);
        $this->assertEquals(22.5, $data['optical_loss_db']);
        $this->assertEquals('Banani Bridge Crossing near Road 11', $data['estimated_location_address']);
    }

    public function test_field_ops_mobile_offline_event_sync_and_qr_scanning(): void
    {
        // 1. Setup field technician
        /** @var FieldTechnician $tech */
        $tech = FieldTechnician::withoutGlobalScopes()->create([
            'technician_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'name' => 'Jashim Uddin',
            'phone' => '+8801722334455',
            'zone' => 'Mirpur-Zone-10',
            'status' => 'available',
            'current_lat' => 23.8068,
            'current_lng' => 90.3685,
        ]);

        // 2. Setup inventory warehouse & hardware item with serial
        $wh = InventoryWarehouse::withoutGlobalScopes()->first();
        $whId = $wh ? (string) $wh->warehouse_id : (string) Str::uuid();

        $serial = 'ZTEG'.strtoupper(Str::random(8));
        /** @var InventoryItem $item */
        $item = InventoryItem::withoutGlobalScopes()->create([
            'item_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'warehouse_id' => $whId,
            'serial_number' => $serial,
            'model' => 'ZTE F660 V5',
            'item_type' => 'onu',
            'status' => 'in_stock',
            'unit_cost_bdt' => 2100.00,
            'created_at' => Carbon::now(),
        ]);

        // 3. Mobile QR Scanner test
        $qrResp = $this->postJson('/api/v1/field-mobile/scan-qr', [
            'tenant_id' => 1,
            'qr_token' => "ASSET:ONU:{$serial}",
            'technician_id' => $tech->technician_id,
        ]);
        $qrResp->assertStatus(200);
        $this->assertTrue($qrResp->json('data.verified'));
        $this->assertEquals($item->item_id, $qrResp->json('data.item_id'));
        $this->assertEquals($serial, $qrResp->json('data.serial_number'));

        // 4. Ingest Batch Offline Events (simulating sync after working in offline basement/vault)
        $syncResp = $this->postJson('/api/v1/field-mobile/sync-offline', [
            'tenant_id' => 1,
            'technician_id' => $tech->technician_id,
            'device_uuid' => 'DEV-TAB-SAMSUNG-0099',
            'events' => [
                [
                    'event_type' => 'splice_photo_upload',
                    'event_data' => ['closure_id' => 'FAT-09', 'photo_url' => '/storage/splices/fat09.jpg'],
                ],
                [
                    'event_type' => 'customer_sign_off',
                    'event_data' => ['order_id' => (string) Str::uuid(), 'customer_name' => 'Md. Faruk'],
                ],
            ],
        ]);
        $syncResp->assertStatus(201);
        $this->assertEquals('success', $syncResp->json('status'));
        $this->assertEquals(2, $syncResp->json('synced_count'));
    }

    public function test_ai_capacity_exhaustion_forecasting_and_nearest_technician_dispatch(): void
    {
        // 1. Forecast PON Port Capacity (Current 88% utilization, 2% daily growth -> 6 days to 100% saturation)
        $forecastResp = $this->postJson('/api/v1/analytics/capacity/forecast', [
            'tenant_id' => 1,
            'resource_type' => 'pon_port',
            'resource_id' => 'OLT-01/SLOT1/PORT8',
            'current_utilization_pct' => 88.0,
            'daily_growth_rate_pct' => 2.0,
        ]);
        $forecastResp->assertStatus(201);

        $data = $forecastResp->json('data');
        $this->assertEquals(6, $data['days_to_exhaustion']);
        $this->assertStringContainsString('CRITICAL', $data['recommended_action']);
        $this->assertStringContainsStringIgnoringCase('split', $data['recommended_action']);

        // 2. Dispatch Nearest Field Splicer to Optical Cut (Banani: 23.7937, 90.4066)
        FieldTechnician::withoutGlobalScopes()->create([
            'technician_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'name' => 'Habibur Rahman (Banani Splicer)',
            'phone' => '+8801911998877',
            'zone' => 'Banani',
            'status' => 'available',
            'current_lat' => 23.7940,
            'current_lng' => 90.4070, // ~60 meters away
        ]);

        $nearResp = $this->postJson('/api/v1/analytics/capacity/nearest-technician', [
            'tenant_id' => 1,
            'lat' => 23.7937,
            'lng' => 90.4066,
        ]);
        $nearResp->assertStatus(200);

        $techData = $nearResp->json('data');
        $this->assertNotNull($techData);
        $this->assertEquals('Habibur Rahman (Banani Splicer)', $techData['name']);
        $this->assertLessThan(1.0, $techData['distance_km']);
        $this->assertGreaterThanOrEqual(5, $techData['estimated_eta_minutes']);
    }
}
