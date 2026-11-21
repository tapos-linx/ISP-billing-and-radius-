<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Olt;
use App\Models\Onu;
use App\Services\Ipam\IpamService;
use App\Services\Monitoring\DevicePollingService;
use App\Services\Network\RouterOsApiClient;
use App\Services\Olt\OltManagerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * P0-9, P0-10, P0-11, P0-12: Network Operations, MikroTik, OLT, Polling & IPAM Controller
 */
class NetworkOperationsController extends Controller
{
    public function __construct(
        protected RouterOsApiClient $routerOs,
        protected OltManagerService $oltManager,
        protected DevicePollingService $pollingService,
        protected IpamService $ipamService
    ) {}

    public function getMikrotikSessions(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'pppoe_sessions' => $this->routerOs->getActivePppoeSessions(),
                'hotspot_sessions' => $this->routerOs->getActiveHotspotSessions(),
            ],
        ]);
    }

    public function triggerMikrotikBackup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nas_identity' => 'required|string|max:64',
        ]);

        $backupResult = $this->routerOs->backupToMinio($validated['nas_identity']);

        return response()->json([
            'status' => ($backupResult['success'] ?? false) ? 'success' : 'failed',
            'data' => $backupResult,
        ]);
    }

    public function discoverOlts(string $id): JsonResponse
    {
        $olt = Olt::withoutGlobalScopes()->where('olt_id', $id)->firstOrFail();
        $discovered = $this->oltManager->discoverOnus($olt);

        return response()->json([
            'status' => 'success',
            'olt' => $olt->name,
            'discovered_count' => count($discovered),
            'data' => $discovered,
        ]);
    }

    public function provisionOnu(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'serial_number' => 'required|string',
            'pon_port' => 'required|string',
            'vlan_id' => 'required|integer',
            'subscriber_id' => 'nullable|uuid',
        ]);

        $olt = Olt::withoutGlobalScopes()->where('olt_id', $id)->firstOrFail();

        $onu = Onu::withoutGlobalScopes()->create([
            'onu_id' => (string) Str::uuid(),
            'tenant_id' => (int) $olt->tenant_id,
            'olt_id' => (string) $olt->olt_id,
            'subscriber_id' => $validated['subscriber_id'] ?? null,
            'pon_port' => $validated['pon_port'],
            'serial_number' => $validated['serial_number'],
            'vlan_id' => $validated['vlan_id'],
            'status' => 'discovered',
            'created_at' => Carbon::now(),
        ]);

        $result = $this->oltManager->provision($olt, $onu);
        $optical = $this->oltManager->getOpticalPower($olt, $onu);

        return response()->json([
            'status' => 'success',
            'onu' => $onu,
            'provisioning' => $result,
            'optical' => $optical,
        ], 201);
    }

    public function pollDevices(): JsonResponse
    {
        $results = $this->pollingService->pollAllDevices();

        return response()->json([
            'status' => 'success',
            'summary' => $results,
        ]);
    }

    public function splitSubnet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cidr' => 'required|string',
        ]);

        $split = $this->ipamService->splitSubnet($validated['cidr']);

        return response()->json([
            'status' => 'success',
            'data' => $split,
        ]);
    }

    public function lookupAbuse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ip' => 'required|ip',
            'timestamp' => 'required|date',
        ]);

        $lookup = $this->ipamService->lookupAbuseHistory($validated['ip'], Carbon::parse($validated['timestamp']));

        if (! $lookup) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'No active subscriber allocation recorded for this IP at the given timestamp.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $lookup,
        ]);
    }
}
