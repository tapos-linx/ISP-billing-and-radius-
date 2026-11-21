<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Analytics\CapacityForecastingService;
use App\Services\FieldOps\MobileFieldOpsService;
use App\Services\Monitoring\OtdrFaultLocalizationService;
use App\Services\Network\BgpPeeringAnalysisService;
use App\Services\Network\DnsSinkholeService;
use App\Services\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 (P2 Batch 2): Network Intelligence, BGP/IXP, DNS Sinkhole, OTDR & AI Forecasting Controller
 */
class NetworkAndFieldIntelligenceController extends Controller
{
    public function __construct(
        protected BgpPeeringAnalysisService $bgpService,
        protected DnsSinkholeService $dnsService,
        protected OtdrFaultLocalizationService $otdrService,
        protected MobileFieldOpsService $mobileService,
        protected CapacityForecastingService $capacityService,
        protected TenantContext $tenantContext
    ) {}

    // --- P2-2: BGP Peering & IXP Optimization ---

    public function registerBgpPeer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'peer_name' => 'required|string',
            'peer_type' => 'nullable|string|in:ixp,transit,cache,private',
            'remote_asn' => 'required|integer',
            'remote_ip' => 'required|ip',
            'local_ip' => 'required|ip',
            'status' => 'nullable|string|in:established,idle,active,down',
            'prefixes_received' => 'nullable|integer',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $peer = $this->bgpService->registerPeer($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $peer,
        ], 201);
    }

    public function recordBgpTelemetry(Request $request, string $peerId): JsonResponse
    {
        $validated = $request->validate([
            'in_bps' => 'required|integer|min:0',
            'out_bps' => 'required|integer|min:0',
            'uptime_seconds' => 'nullable|integer|min:0',
            'flaps_count' => 'nullable|integer|min:0',
        ]);

        $telemetry = $this->bgpService->recordTelemetry(
            $peerId,
            (int) $validated['in_bps'],
            (int) $validated['out_bps'],
            (int) ($validated['uptime_seconds'] ?? 3600),
            (int) ($validated['flaps_count'] ?? 0)
        );

        return response()->json([
            'status' => 'success',
            'data' => $telemetry,
        ], 201);
    }

    public function getPeeringRatios(Request $request): JsonResponse
    {
        $tenantId = (int) ($request->query('tenant_id', $this->tenantContext->getTenantId() ?? 1));
        $ratios = $this->bgpService->analyzePeeringRatios($tenantId);

        return response()->json([
            'status' => 'success',
            'data' => $ratios,
        ]);
    }

    // --- P2-3: DNS Sinkholing & Regulatory Content Filtering ---

    public function addSinkholeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'domain' => 'required|string',
            'category' => 'nullable|string',
            'sinkhole_ip' => 'nullable|ip',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $rule = $this->dnsService->addRule(
            $tenantId,
            $validated['domain'],
            $validated['category'] ?? 'btrc_mandatory',
            $validated['sinkhole_ip'] ?? '10.254.254.254'
        );

        return response()->json([
            'status' => 'success',
            'data' => $rule,
        ], 201);
    }

    public function evaluateDnsQuery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'domain' => 'required|string',
            'client_ip' => 'required|ip',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $result = $this->dnsService->evaluateDnsQuery($tenantId, $validated['domain'], $validated['client_ip']);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    public function getDnsCompliance(Request $request): JsonResponse
    {
        $tenantId = (int) ($request->query('tenant_id', $this->tenantContext->getTenantId() ?? 1));
        $summary = $this->dnsService->getComplianceSummary($tenantId);

        return response()->json([
            'status' => 'success',
            'data' => $summary,
        ]);
    }

    // --- P2-4: OTDR Distance Fault Localization ---

    public function analyzeOtdrTrace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'circuit_or_port_ref' => 'required|string',
            'optical_loss_db' => 'required|numeric',
            'fault_distance_meters' => 'nullable|numeric',
            'round_trip_time_ns' => 'nullable|numeric',
            'fiber_refractive_index' => 'nullable|numeric',
            'estimated_location_address' => 'nullable|string',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $trace = $this->otdrService->analyzeTrace($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $trace,
        ], 201);
    }

    // --- P2-8: Field Ops Mobile App Offline Synchronization ---

    public function scanAssetQr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'qr_token' => 'required|string',
            'technician_id' => 'required|uuid',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $result = $this->mobileService->scanAssetQr($tenantId, $validated['qr_token'], $validated['technician_id']);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    public function syncOfflineEvents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'technician_id' => 'required|uuid',
            'device_uuid' => 'required|string',
            'events' => 'required|array',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $result = $this->mobileService->syncOfflineEvents(
            $tenantId,
            $validated['technician_id'],
            $validated['device_uuid'],
            $validated['events']
        );

        return response()->json($result, 201);
    }

    // --- P2-13: AI Capacity Exhaustion Forecasting & Nearest Dispatch ---

    public function forecastResource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'resource_type' => 'required|string|in:pon_port,olt_uplink,cgnat_pool',
            'resource_id' => 'required|string',
            'current_utilization_pct' => 'required|numeric|min:0|max:100',
            'daily_growth_rate_pct' => 'required|numeric|min:0',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $forecast = $this->capacityService->forecastResource(
            $tenantId,
            $validated['resource_type'],
            $validated['resource_id'],
            (float) $validated['current_utilization_pct'],
            (float) $validated['daily_growth_rate_pct']
        );

        return response()->json([
            'status' => 'success',
            'data' => $forecast,
        ], 201);
    }

    public function findNearestTechnician(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $tech = $this->capacityService->findNearestTechnician($tenantId, (float) $validated['lat'], (float) $validated['lng']);

        return response()->json([
            'status' => 'success',
            'data' => $tech,
        ]);
    }
}
