<?php

declare(strict_types=1);

namespace App\Services\FieldOps;

use App\Models\FieldTechnician;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * P1-13: Field Workforce Management & Dispatching Service
 * Coordinates field technician scheduling, geolocation tracking,
 * work order lifecycle, and digital customer signature sign-off.
 */
class FieldWorkforceService
{
    /**
     * Register or update a field technician profile
     *
     * @param  array<string, mixed>  $data
     */
    public function registerTechnician(int $tenantId, array $data): FieldTechnician
    {
        $techId = (string) ($data['technician_id'] ?? Str::uuid());

        /** @var FieldTechnician $technician */
        $technician = FieldTechnician::withoutGlobalScopes()->updateOrCreate(
            [
                'technician_id' => $techId,
                'tenant_id' => $tenantId,
            ],
            [
                'name' => (string) $data['name'],
                'phone' => (string) $data['phone'],
                'email' => isset($data['email']) ? (string) $data['email'] : null,
                'zone' => (string) ($data['zone'] ?? 'Dhaka-North'),
                'status' => (string) ($data['status'] ?? 'available'),
                'current_lat' => isset($data['lat']) ? (float) $data['lat'] : null,
                'current_lng' => isset($data['lng']) ? (float) $data['lng'] : null,
                'created_at' => Carbon::now(),
            ]
        );

        return $technician;
    }

    /**
     * Update real-time GPS coordinates of a field technician
     */
    public function updateGpsLocation(string $technicianId, float $lat, float $lng): ?FieldTechnician
    {
        /** @var FieldTechnician|null $tech */
        $tech = FieldTechnician::withoutGlobalScopes()->find($technicianId);
        if ($tech !== null) {
            $tech->current_lat = $lat;
            $tech->current_lng = $lng;
            $tech->save();
        }

        return $tech;
    }

    /**
     * Retrieve available technicians operating in a specific deployment zone
     *
     * @return Collection<int, FieldTechnician>
     */
    public function getAvailableTechniciansInZone(int $tenantId, string $zone): Collection
    {
        return FieldTechnician::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('zone', $zone)
            ->where('status', 'available')
            ->get();
    }

    /**
     * Create a new field dispatch work order
     *
     * @param  array<string, mixed>  $data
     */
    public function createWorkOrder(int $tenantId, array $data): WorkOrder
    {
        $orderId = (string) ($data['order_id'] ?? Str::uuid());
        $orderNumber = (string) ($data['order_number'] ?? 'WO-'.strtoupper(Str::random(8)));

        /** @var WorkOrder $order */
        $order = WorkOrder::withoutGlobalScopes()->create([
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'order_number' => $orderNumber,
            'subscriber_id' => $data['subscriber_id'] ?? null,
            'ticket_id' => $data['ticket_id'] ?? null,
            'technician_id' => $data['technician_id'] ?? null,
            'type' => (string) ($data['type'] ?? 'repair'),
            'priority' => (string) ($data['priority'] ?? 'medium'),
            'status' => isset($data['technician_id']) ? 'assigned' : 'pending',
            'address' => (string) ($data['address'] ?? 'Dhaka, Bangladesh'),
            'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
            'lng' => isset($data['lng']) ? (float) $data['lng'] : null,
            'scheduled_at' => isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : Carbon::now(),
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            'created_at' => Carbon::now(),
        ]);

        if (! empty($data['technician_id'])) {
            FieldTechnician::withoutGlobalScopes()
                ->where('technician_id', $data['technician_id'])
                ->update(['status' => 'on_job']);
        }

        return $order;
    }

    /**
     * Assign a work order to a technician and update status to 'assigned'
     */
    public function assignWorkOrder(string $orderId, string $technicianId): ?WorkOrder
    {
        /** @var WorkOrder|null $order */
        $order = WorkOrder::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            return null;
        }

        $order->technician_id = $technicianId;
        $order->status = 'assigned';
        $order->save();

        FieldTechnician::withoutGlobalScopes()
            ->where('technician_id', $technicianId)
            ->update(['status' => 'on_job']);

        return $order;
    }

    /**
     * Complete a work order with digital customer signature sign-off
     */
    public function completeWorkOrder(string $orderId, string $customerSignature, ?string $notes = null): ?WorkOrder
    {
        /** @var WorkOrder|null $order */
        $order = WorkOrder::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            return null;
        }

        $order->status = 'completed';
        $order->customer_signature = $customerSignature;
        $order->completed_at = Carbon::now();
        if ($notes !== null) {
            $order->notes = ($order->notes ? $order->notes."\n" : '').$notes;
        }
        $order->save();

        // Release technician back to available status
        if ($order->technician_id !== null) {
            // Check if technician has other active jobs
            $activeJobs = WorkOrder::withoutGlobalScopes()
                ->where('technician_id', $order->technician_id)
                ->whereIn('status', ['assigned', 'in_progress'])
                ->count();

            if ($activeJobs === 0) {
                FieldTechnician::withoutGlobalScopes()
                    ->where('technician_id', $order->technician_id)
                    ->update(['status' => 'available']);
            }
        }

        return $order;
    }
}
