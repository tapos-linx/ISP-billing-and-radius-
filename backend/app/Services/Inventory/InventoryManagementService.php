<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryWarehouse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * P1-14: Carrier Serialized Inventory & Warehouse Asset Tracking Service
 * Tracks ONUs, optical transceivers, routers, and drop cables by unique serial/FSAN/MAC
 * across central/zonal depots, field vans, and customer premises.
 */
class InventoryManagementService
{
    /**
     * Create or retrieve an inventory warehouse depot
     */
    public function createWarehouse(int $tenantId, string $name, string $location, ?string $managerName = null): InventoryWarehouse
    {
        /** @var InventoryWarehouse $warehouse */
        $warehouse = InventoryWarehouse::withoutGlobalScopes()->create([
            'warehouse_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'name' => $name,
            'location' => $location,
            'manager_name' => $managerName,
            'created_at' => Carbon::now(),
        ]);

        return $warehouse;
    }

    /**
     * Ingest/receive serialized hardware into a target warehouse
     *
     * @param  array<string, mixed>  $data
     */
    public function receiveItem(int $tenantId, array $data): InventoryItem
    {
        $itemId = (string) ($data['item_id'] ?? Str::uuid());

        /** @var InventoryItem $item */
        $item = InventoryItem::withoutGlobalScopes()->create([
            'item_id' => $itemId,
            'tenant_id' => $tenantId,
            'warehouse_id' => (string) $data['warehouse_id'],
            'serial_number' => (string) $data['serial_number'],
            'model' => (string) $data['model'],
            'item_type' => (string) ($data['item_type'] ?? 'onu'),
            'status' => 'in_stock',
            'unit_cost_bdt' => isset($data['unit_cost_bdt']) ? (float) $data['unit_cost_bdt'] : 0.0,
            'created_at' => Carbon::now(),
        ]);

        return $item;
    }

    /**
     * Transfer an inventory item to another warehouse depot
     */
    public function transferWarehouse(string $itemId, string $destinationWarehouseId): ?InventoryItem
    {
        /** @var InventoryItem|null $item */
        $item = InventoryItem::withoutGlobalScopes()->find($itemId);
        if ($item !== null) {
            $item->warehouse_id = $destinationWarehouseId;
            $item->save();
        }

        return $item;
    }

    /**
     * Assign a CPE device (ONU, Router) to a subscriber upon installation
     */
    public function assignToSubscriber(string $itemId, string $subscriberId): ?InventoryItem
    {
        /** @var InventoryItem|null $item */
        $item = InventoryItem::withoutGlobalScopes()->find($itemId);
        if ($item !== null) {
            $item->subscriber_id = $subscriberId;
            $item->status = 'assigned';
            $item->save();
        }

        return $item;
    }

    /**
     * Return an item from a subscriber back to warehouse (e.g. upon service termination or hardware upgrade)
     */
    public function returnToWarehouse(string $itemId, string $warehouseId, string $status = 'in_stock'): ?InventoryItem
    {
        /** @var InventoryItem|null $item */
        $item = InventoryItem::withoutGlobalScopes()->find($itemId);
        if ($item !== null) {
            $item->subscriber_id = null;
            $item->warehouse_id = $warehouseId;
            $item->status = $status;
            $item->save();
        }

        return $item;
    }

    /**
     * Get stock inventory summary for a warehouse
     *
     * @return array<string, mixed>
     */
    public function getWarehouseStockSummary(string $warehouseId): array
    {
        /** @var Collection<int, InventoryItem> $items */
        $items = InventoryItem::withoutGlobalScopes()
            ->where('warehouse_id', $warehouseId)
            ->get();

        $byType = [];
        $byStatus = [];

        foreach ($items as $item) {
            $type = (string) $item->item_type;
            $status = (string) $item->status;

            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        return [
            'warehouse_id' => $warehouseId,
            'total_items' => $items->count(),
            'by_type' => $byType,
            'by_status' => $byStatus,
        ];
    }
}
