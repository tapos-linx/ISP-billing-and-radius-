<?php

declare(strict_types=1);

namespace App\Services\FieldOps;

use App\Models\InventoryItem;
use App\Models\MobileSyncEvent;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * P2-8: Advanced Field Ops Mobile App Offline Synchronization & Asset QR Scanning Service
 */
class MobileFieldOpsService
{
    /**
     * Decode and verify optical/CPE hardware asset QR code tag
     *
     * @return array<string, mixed>
     */
    public function scanAssetQr(int $tenantId, string $qrToken, string $technicianId): array
    {
        // QR Code formats: "ASSET:ONU:{serial}" or raw serial number
        $serial = str_starts_with($qrToken, 'ASSET:ONU:')
            ? substr($qrToken, 10)
            : $qrToken;

        /** @var InventoryItem|null $item */
        $item = InventoryItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('serial_number', $serial)
            ->first();

        if ($item === null) {
            return [
                'verified' => false,
                'qr_token' => $qrToken,
                'serial_number' => $serial,
                'status' => 'not_found',
                'message' => 'Scanned hardware asset not recognized in central warehouse inventory.',
            ];
        }

        return [
            'verified' => true,
            'item_id' => $item->item_id,
            'serial_number' => $item->serial_number,
            'model' => $item->model,
            'item_type' => $item->item_type,
            'status' => $item->status,
            'warehouse_id' => $item->warehouse_id,
            'subscriber_id' => $item->subscriber_id,
            'scanned_by_technician_id' => $technicianId,
        ];
    }

    /**
     * Ingest batch of offline events queued during loss of field cellular signal
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    public function syncOfflineEvents(int $tenantId, string $technicianId, string $deviceUuid, array $events): array
    {
        $syncedCount = 0;
        $eventIds = [];

        foreach ($events as $event) {
            $eventId = (string) Str::uuid();

            MobileSyncEvent::withoutGlobalScopes()->create([
                'event_id' => $eventId,
                'tenant_id' => $tenantId,
                'technician_id' => $technicianId,
                'device_uuid' => $deviceUuid,
                'event_type' => (string) ($event['event_type'] ?? 'generic_action'),
                'event_data' => $event['event_data'] ?? [],
                'synced_at' => Carbon::now(),
            ]);

            $syncedCount++;
            $eventIds[] = $eventId;
        }

        return [
            'status' => 'success',
            'synced_count' => $syncedCount,
            'technician_id' => $technicianId,
            'device_uuid' => $deviceUuid,
            'event_ids' => $eventIds,
        ];
    }
}
