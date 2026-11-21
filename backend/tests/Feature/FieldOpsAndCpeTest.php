<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FieldTechnician;
use App\Models\NetworkAlarm;
use App\Models\Subscriber;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 (P1 Batch 2): CPE Management, Optical Alarms & Field Operations Feature Test
 */
class FieldOpsAndCpeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_genieacs_tr069_reboot_and_wifi_and_pppoe_provisioning(): void
    {
        Http::fake([
            '*/devices/TEST-CPE-001/tasks?connection_request' => Http::response([
                'name' => 'setParameterValues',
                'status' => 200,
            ], 200),
        ]);

        // 1. Reboot CPE
        $rebootResp = $this->postJson('/api/v1/cpe/reboot', [
            'device_id' => 'TEST-CPE-001',
        ]);
        $rebootResp->assertStatus(200);
        $this->assertTrue($rebootResp->json('success'));

        // 2. Configure Wi-Fi SSID and WPA2 Key
        $wifiResp = $this->postJson('/api/v1/cpe/wifi', [
            'device_id' => 'TEST-CPE-001',
            'ssid' => 'Carrier-Fiber-5G',
            'password' => 'SecurePassphrase2026',
        ]);
        $wifiResp->assertStatus(200);
        $this->assertTrue($wifiResp->json('success'));

        // 3. Provision WAN PPPoE credentials
        $pppoeResp = $this->postJson('/api/v1/cpe/pppoe', [
            'device_id' => 'TEST-CPE-001',
            'username' => 'sub_fiber_8899',
            'password' => 'secret_pppoe_pass',
            'vlan_id' => 150,
        ]);
        $pppoeResp->assertStatus(200);
        $this->assertTrue($pppoeResp->json('success'));
    }

    public function test_optical_alarm_classification_dying_gasp_vs_los(): void
    {
        $deviceId = (string) Str::uuid();
        $onuId1 = (string) Str::uuid();
        $onuId2 = (string) Str::uuid();

        // 1. Dying-Gasp capacitor discharge (Subscriber electrical power outage)
        $dyingGaspResp = $this->postJson('/api/v1/cpe/alarms/event', [
            'tenant_id' => 1,
            'device_id' => $deviceId,
            'onu_id' => $onuId1,
            'alarm_type' => 'DYING_GASP',
        ]);
        $dyingGaspResp->assertStatus(200);
        $this->assertEquals('DYING_GASP', $dyingGaspResp->json('data.alarm_type'));
        $this->assertEquals('major', $dyingGaspResp->json('data.severity'));
        $this->assertTrue($dyingGaspResp->json('data.is_root_cause'));

        // 2. Optical LOS / Fiber Core Severance (e.g. optical power -38.5 dBm)
        $losResp = $this->postJson('/api/v1/cpe/alarms/event', [
            'tenant_id' => 1,
            'device_id' => $deviceId,
            'onu_id' => $onuId2,
            'optical_power_dbm' => -38.5,
        ]);
        $losResp->assertStatus(200);
        $this->assertEquals('LOS', $losResp->json('data.alarm_type'));
        $this->assertEquals('critical', $losResp->json('data.severity'));

        // 3. Query active alarms
        $activeResp = $this->getJson('/api/v1/cpe/alarms/active?tenant_id=1');
        $activeResp->assertStatus(200);
        $this->assertNotEmpty($activeResp->json('data'));
    }

    public function test_carrier_topological_alarm_suppression_and_resolution(): void
    {
        // 1. Upstream Switch / OLT Port failure (Root Cause)
        $parentAlarm = NetworkAlarm::withoutGlobalScopes()->create([
            'tenant_id' => 1,
            'device_id' => (string) Str::uuid(),
            'alarm_type' => 'LINK_DOWN',
            'severity' => 'critical',
            'is_root_cause' => true,
            'status' => 'active',
            'detected_at' => Carbon::now(),
        ]);

        // 2. Downstream Child ONU alarms
        $child1 = NetworkAlarm::withoutGlobalScopes()->create([
            'tenant_id' => 1,
            'onu_id' => (string) Str::uuid(),
            'alarm_type' => 'LOS',
            'severity' => 'critical',
            'is_root_cause' => true,
            'status' => 'active',
            'detected_at' => Carbon::now(),
        ]);

        $child2 = NetworkAlarm::withoutGlobalScopes()->create([
            'tenant_id' => 1,
            'onu_id' => (string) Str::uuid(),
            'alarm_type' => 'LOS',
            'severity' => 'critical',
            'is_root_cause' => true,
            'status' => 'active',
            'detected_at' => Carbon::now(),
        ]);

        // 3. Suppress child alarms under parent
        $suppressResp = $this->postJson('/api/v1/cpe/alarms/suppress', [
            'parent_alarm_id' => $parentAlarm->alarm_id,
            'child_alarm_ids' => [$child1->alarm_id, $child2->alarm_id],
        ]);
        $suppressResp->assertStatus(200);
        $this->assertEquals(2, $suppressResp->json('suppressed_count'));

        $child1->refresh();
        $this->assertEquals('suppressed', $child1->status);
        $this->assertFalse($child1->is_root_cause);
        $this->assertEquals($parentAlarm->alarm_id, $child1->suppressed_by_alarm_id);

        // 4. Resolve parent alarm -> clears children
        $resolveResp = $this->postJson("/api/v1/cpe/alarms/{$parentAlarm->alarm_id}/resolve");
        $resolveResp->assertStatus(200);
        $this->assertEquals(2, $resolveResp->json('cleared_children_count'));

        $parentAlarm->refresh();
        $this->assertEquals('cleared', $parentAlarm->status);
        $child1->refresh();
        $this->assertEquals('cleared', $child1->status);
    }

    public function test_field_workforce_technician_dispatch_and_work_order_completion(): void
    {
        // 1. Register technician
        $techResp = $this->postJson('/api/v1/field-ops/technicians', [
            'tenant_id' => 1,
            'name' => 'Tariqul Islam',
            'phone' => '+8801711223344',
            'email' => 'tariqul@carrier.bd',
            'zone' => 'Uttara-Sector-4',
            'status' => 'available',
            'lat' => 23.8759,
            'lng' => 90.3795,
        ]);
        $techResp->assertStatus(201);
        $techId = $techResp->json('data.technician_id');
        $this->assertNotNull($techId);

        // 2. Update GPS location
        $locResp = $this->postJson("/api/v1/field-ops/technicians/{$techId}/location", [
            'lat' => 23.8765,
            'lng' => 90.3802,
        ]);
        $locResp->assertStatus(200);
        $this->assertEquals(23.8765, $locResp->json('data.current_lat'));

        // 3. Create work order
        $orderResp = $this->postJson('/api/v1/field-ops/work-orders', [
            'tenant_id' => 1,
            'type' => 'fiber_splice',
            'priority' => 'high',
            'address' => 'House 12, Road 4, Sector 4, Uttara',
            'notes' => 'Fiber drop cable severed by road excavation',
        ]);
        $orderResp->assertStatus(201);
        $orderId = $orderResp->json('data.order_id');
        $this->assertEquals('pending', $orderResp->json('data.status'));

        // 4. Assign work order to technician -> tech status becomes on_job
        $assignResp = $this->postJson("/api/v1/field-ops/work-orders/{$orderId}/assign", [
            'technician_id' => $techId,
        ]);
        $assignResp->assertStatus(200);
        $this->assertEquals('assigned', $assignResp->json('data.status'));

        $tech = FieldTechnician::withoutGlobalScopes()->find($techId);
        $this->assertNotNull($tech);
        $this->assertEquals('on_job', $tech->status);

        // 5. Complete work order with customer signature -> tech status returns to available
        $completeResp = $this->postJson("/api/v1/field-ops/work-orders/{$orderId}/complete", [
            'customer_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            'notes' => 'Fusion spliced core 2, optical Rx restored to -18.2 dBm',
        ]);
        $completeResp->assertStatus(200);
        $this->assertEquals('completed', $completeResp->json('data.status'));
        $this->assertNotNull($completeResp->json('data.completed_at'));

        $tech->refresh();
        $this->assertEquals('available', $tech->status);
    }

    public function test_serialized_inventory_tracking_and_subscriber_assignment(): void
    {
        // 1. Create warehouse depot
        $whResp = $this->postJson('/api/v1/field-ops/inventory/warehouses', [
            'tenant_id' => 1,
            'name' => 'Uttara Hub Warehouse',
            'location' => 'Sector 3, Uttara, Dhaka',
            'manager_name' => 'Aminul Islam',
        ]);
        $whResp->assertStatus(201);
        $warehouseId = $whResp->json('data.warehouse_id');
        $this->assertNotNull($warehouseId);

        // 2. Ingest serialized hardware (ONU)
        $serial = 'HWTC'.strtoupper(Str::random(8));
        $itemResp = $this->postJson('/api/v1/field-ops/inventory/items', [
            'tenant_id' => 1,
            'warehouse_id' => $warehouseId,
            'serial_number' => $serial,
            'model' => 'Huawei HG8546M',
            'item_type' => 'onu',
            'unit_cost_bdt' => 1850.00,
        ]);
        $itemResp->assertStatus(201);
        $itemId = $itemResp->json('data.item_id');
        $this->assertEquals('in_stock', $itemResp->json('data.status'));

        // 3. Assign item to subscriber
        $sub = Subscriber::withoutGlobalScopes()->first();
        $subId = $sub ? (string) $sub->subscriber_id : (string) Str::uuid();

        $assignResp = $this->postJson("/api/v1/field-ops/inventory/items/{$itemId}/assign", [
            'subscriber_id' => $subId,
        ]);
        $assignResp->assertStatus(200);
        $this->assertEquals('assigned', $assignResp->json('data.status'));
        $this->assertEquals($subId, $assignResp->json('data.subscriber_id'));

        // 4. Return item to warehouse
        $returnResp = $this->postJson("/api/v1/field-ops/inventory/items/{$itemId}/return", [
            'warehouse_id' => $warehouseId,
            'status' => 'in_stock',
        ]);
        $returnResp->assertStatus(200);
        $this->assertEquals('in_stock', $returnResp->json('data.status'));
        $this->assertNull($returnResp->json('data.subscriber_id'));

        // 5. Stock summary
        $stockResp = $this->getJson("/api/v1/field-ops/inventory/warehouses/{$warehouseId}/stock");
        $stockResp->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $stockResp->json('data.total_items'));
    }
}
