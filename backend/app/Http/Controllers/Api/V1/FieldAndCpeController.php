<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NetworkAlarm;
use App\Services\Cpe\GenieAcsClient;
use App\Services\FieldOps\FieldWorkforceService;
use App\Services\Inventory\InventoryManagementService;
use App\Services\Monitoring\AlarmSuppressionService;
use App\Services\Monitoring\OpticalAlarmService;
use App\Services\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2 (P1 Batch 2): CPE Management, Optical Alarms & Field Operations Controller
 */
class FieldAndCpeController extends Controller
{
    public function __construct(
        protected GenieAcsClient $cpeClient,
        protected OpticalAlarmService $alarmService,
        protected AlarmSuppressionService $suppressionService,
        protected FieldWorkforceService $fieldService,
        protected InventoryManagementService $inventoryService,
        protected TenantContext $tenantContext
    ) {}

    // --- CPE Remote Provisioning (TR-069) ---

    public function rebootCpe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string',
        ]);

        $result = $this->cpeClient->rebootDevice($validated['device_id']);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 500);
    }

    public function configureWifi(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string',
            'ssid' => 'required|string|min:2|max:32',
            'password' => 'required|string|min:8|max:64',
        ]);

        $result = $this->cpeClient->configureWifi($validated['device_id'], $validated['ssid'], $validated['password']);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 500);
    }

    public function provisionWanPppoe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'vlan_id' => 'nullable|integer|min:1|max:4094',
        ]);

        $result = $this->cpeClient->provisionWanPppoe(
            $validated['device_id'],
            $validated['username'],
            $validated['password'],
            (int) ($validated['vlan_id'] ?? 100)
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 500);
    }

    // --- Optical Alarms & Topology Suppression ---

    public function handleAlarmEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'device_id' => 'nullable|string',
            'onu_id' => 'nullable|string',
            'alarm_type' => 'nullable|string',
            'optical_power_dbm' => 'nullable|numeric',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $alarm = $this->alarmService->processEvent($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $alarm,
        ]);
    }

    public function getActiveAlarms(Request $request): JsonResponse
    {
        $tenantId = (int) ($request->query('tenant_id', $this->tenantContext->getTenantId() ?? 1));
        $alarms = $this->alarmService->getActiveAlarms($tenantId);

        return response()->json([
            'status' => 'success',
            'data' => $alarms,
        ]);
    }

    public function suppressAlarms(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_alarm_id' => 'required|integer',
            'child_alarm_ids' => 'required|array',
            'child_alarm_ids.*' => 'integer',
        ]);

        /** @var NetworkAlarm $parentAlarm */
        $parentAlarm = NetworkAlarm::withoutGlobalScopes()->findOrFail($validated['parent_alarm_id']);
        $count = $this->suppressionService->batchSuppress($parentAlarm, $validated['child_alarm_ids']);

        return response()->json([
            'status' => 'success',
            'suppressed_count' => $count,
        ]);
    }

    public function resolveParentAlarm(int $alarmId): JsonResponse
    {
        /** @var NetworkAlarm $parentAlarm */
        $parentAlarm = NetworkAlarm::withoutGlobalScopes()->findOrFail($alarmId);
        $count = $this->suppressionService->resolveParentAlarm($parentAlarm);

        return response()->json([
            'status' => 'success',
            'cleared_children_count' => $count,
        ]);
    }

    // --- Field Workforce & Work Orders ---

    public function registerTechnician(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'name' => 'required|string',
            'phone' => 'required|string',
            'email' => 'nullable|email',
            'zone' => 'required|string',
            'status' => 'nullable|string|in:available,on_job,off_duty',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $technician = $this->fieldService->registerTechnician($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $technician,
        ], 201);
    }

    public function updateTechnicianLocation(Request $request, string $technicianId): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $technician = $this->fieldService->updateGpsLocation($technicianId, (float) $validated['lat'], (float) $validated['lng']);

        return response()->json([
            'status' => 'success',
            'data' => $technician,
        ]);
    }

    public function createWorkOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'type' => 'required|string',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'address' => 'required|string',
            'technician_id' => 'nullable|uuid',
            'subscriber_id' => 'nullable|uuid',
            'ticket_id' => 'nullable|uuid',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'scheduled_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $order = $this->fieldService->createWorkOrder($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ], 201);
    }

    public function assignWorkOrder(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'technician_id' => 'required|uuid',
        ]);

        $order = $this->fieldService->assignWorkOrder($orderId, $validated['technician_id']);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }

    public function completeWorkOrder(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'customer_signature' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $order = $this->fieldService->completeWorkOrder($orderId, $validated['customer_signature'], $validated['notes'] ?? null);

        return response()->json([
            'status' => 'success',
            'data' => $order,
        ]);
    }

    // --- Inventory & Warehouse Management ---

    public function createWarehouse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'name' => 'required|string',
            'location' => 'required|string',
            'manager_name' => 'nullable|string',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $warehouse = $this->inventoryService->createWarehouse(
            $tenantId,
            $validated['name'],
            $validated['location'],
            $validated['manager_name'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'data' => $warehouse,
        ], 201);
    }

    public function receiveItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'warehouse_id' => 'required|uuid',
            'serial_number' => 'required|string',
            'model' => 'required|string',
            'item_type' => 'required|string',
            'unit_cost_bdt' => 'nullable|numeric|min:0',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $item = $this->inventoryService->receiveItem($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $item,
        ], 201);
    }

    public function assignItemToSubscriber(Request $request, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|uuid',
        ]);

        $item = $this->inventoryService->assignToSubscriber($itemId, $validated['subscriber_id']);

        return response()->json([
            'status' => 'success',
            'data' => $item,
        ]);
    }

    public function returnItemToWarehouse(Request $request, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|uuid',
            'status' => 'nullable|string|in:in_stock,faulty,scrapped',
        ]);

        $item = $this->inventoryService->returnToWarehouse($itemId, $validated['warehouse_id'], $validated['status'] ?? 'in_stock');

        return response()->json([
            'status' => 'success',
            'data' => $item,
        ]);
    }

    public function getWarehouseStock(string $warehouseId): JsonResponse
    {
        $summary = $this->inventoryService->getWarehouseStockSummary($warehouseId);

        return response()->json([
            'status' => 'success',
            'data' => $summary,
        ]);
    }
}
