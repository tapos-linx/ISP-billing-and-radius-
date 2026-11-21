<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Billing\PercentileBillingService;
use App\Services\Enterprise\EnterpriseSlaService;
use App\Services\Tenant\TenantContext;
use App\Services\Voip\VoipCdrRatingService;
use App\Services\Wholesale\WholesaleCarrierService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 (P2 Batch 1): Enterprise SLA, Wholesale Carrier, 95th Percentile & VoIP Rating Controller
 */
class EnterpriseAndVoipController extends Controller
{
    public function __construct(
        protected EnterpriseSlaService $slaService,
        protected WholesaleCarrierService $wholesaleService,
        protected PercentileBillingService $percentileService,
        protected VoipCdrRatingService $voipService,
        protected TenantContext $tenantContext
    ) {}

    // --- P2-1: Enterprise Circuits & SLA Monitoring ---

    public function registerCircuit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'subscriber_id' => 'nullable|uuid',
            'circuit_ref' => 'required|string',
            'committed_bandwidth_mbps' => 'required|integer|min:1',
            'sla_target_availability_pct' => 'nullable|numeric|min:90|max:100',
            'max_latency_ms' => 'nullable|integer',
            'max_jitter_ms' => 'nullable|integer',
            'max_packet_loss_pct' => 'nullable|numeric',
            'monthly_rate_bdt' => 'required|numeric|min:0',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $circuit = $this->slaService->registerCircuit($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $circuit,
        ], 201);
    }

    public function auditCircuitSla(Request $request, string $circuitId): JsonResponse
    {
        $validated = $request->validate([
            'billing_month' => 'required|string', // e.g. '2026-11'
            'downtime_minutes' => 'required|integer|min:0',
        ]);

        $breach = $this->slaService->auditMonthlySla($circuitId, $validated['billing_month'], (int) $validated['downtime_minutes']);

        return response()->json([
            'status' => 'success',
            'data' => $breach,
        ]);
    }

    // --- P2-5: Wholesale Carrier Interconnect & L2TP Tunnels ---

    public function registerCarrier(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'carrier_name' => 'required|string',
            'carrier_code' => 'required|string',
            'account_number' => 'required|string',
            'rate_per_mbps_bdt' => 'nullable|numeric|min:0',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $carrier = $this->wholesaleService->registerCarrier($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $carrier,
        ], 201);
    }

    public function provisionTunnel(Request $request, string $carrierId): JsonResponse
    {
        $validated = $request->validate([
            'tunnel_name' => 'required|string',
            'local_endpoint_ip' => 'required|ip',
            'remote_endpoint_ip' => 'required|ip',
            'shared_secret' => 'required|string',
            'committed_mbps' => 'nullable|integer|min:10',
        ]);

        $tunnel = $this->wholesaleService->provisionTunnel($carrierId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $tunnel,
        ], 201);
    }

    public function generateWholesaleInvoice(Request $request, string $carrierId): JsonResponse
    {
        $validated = $request->validate([
            'billed_mbps' => 'required|integer|min:1',
        ]);

        $invoice = $this->wholesaleService->generateWholesaleInvoice($carrierId, (int) $validated['billed_mbps']);

        return response()->json([
            'status' => 'success',
            'data' => $invoice,
        ], 201);
    }

    // --- P2-7: 95th Percentile Burstable Bandwidth Billing ---

    public function recordBandwidthSample(Request $request, string $circuitId): JsonResponse
    {
        $validated = $request->validate([
            'in_mbps' => 'required|numeric|min:0',
            'out_mbps' => 'required|numeric|min:0',
            'sampled_at' => 'nullable|date',
        ]);

        $sample = $this->percentileService->recordSample(
            $circuitId,
            (float) $validated['in_mbps'],
            (float) $validated['out_mbps'],
            isset($validated['sampled_at']) ? Carbon::parse($validated['sampled_at']) : null
        );

        return response()->json([
            'status' => 'success',
            'data' => $sample,
        ], 201);
    }

    public function calculate95thPercentile(Request $request, string $circuitId): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'overage_rate_per_mbps' => 'nullable|numeric|min:0',
        ]);

        $result = $this->percentileService->calculate95thPercentile(
            $circuitId,
            Carbon::parse($validated['start_date']),
            Carbon::parse($validated['end_date']),
            isset($validated['overage_rate_per_mbps']) ? (float) $validated['overage_rate_per_mbps'] : 250.0
        );

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    // --- P2-12: SIP Trunks & VoIP CDR Rating ---

    public function registerSipTrunk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'name' => 'required|string',
            'host' => 'required|string',
            'port' => 'nullable|integer',
            'auth_username' => 'nullable|string',
            'auth_password' => 'nullable|string',
            'max_channels' => 'nullable|integer',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $trunk = $this->voipService->registerTrunk($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $trunk,
        ], 201);
    }

    public function ingestVoipCdr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'trunk_id' => 'nullable|uuid',
            'call_id' => 'nullable|string',
            'caller_e164' => 'required|string',
            'callee_e164' => 'required|string',
            'started_at' => 'required|date',
            'answered_at' => 'nullable|date',
            'ended_at' => 'required|date',
            'disposition' => 'nullable|string',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $cdr = $this->voipService->ingestAndRateCdr($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $cdr,
        ], 201);
    }
}
