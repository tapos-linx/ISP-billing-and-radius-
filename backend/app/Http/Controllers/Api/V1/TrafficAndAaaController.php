<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Services\Bandwidth\FupManagementService;
use App\Services\Bandwidth\TimeOfDayBoostService;
use App\Services\Compliance\CgnatCorrelationService;
use App\Services\Radius\MultiVendorVsaService;
use App\Services\Traffic\NetflowAnalysisService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2 (P1 Batch 1): AAA, Traffic & Bandwidth Engineering Controller
 */
class TrafficAndAaaController extends Controller
{
    public function __construct(
        protected FupManagementService $fupService,
        protected TimeOfDayBoostService $boostService,
        protected MultiVendorVsaService $vsaService,
        protected NetflowAnalysisService $netflowService,
        protected CgnatCorrelationService $cgnatService
    ) {}

    public function checkFup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|uuid',
        ]);

        $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $validated['subscriber_id'])->firstOrFail();
        $result = $this->fupService->checkAndApplyFup($subscriber);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    public function purchaseTopup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|uuid',
            'data_gb' => 'required|integer|min:1',
            'price_bdt' => 'required|numeric|min:1',
        ]);

        $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $validated['subscriber_id'])->firstOrFail();
        $dataBytes = $validated['data_gb'] * (1024 ** 3);

        $topup = $this->fupService->purchaseTopup($subscriber, $dataBytes, (float) $validated['price_bdt']);

        return response()->json([
            'status' => 'success',
            'data' => $topup,
        ], 201);
    }

    public function evaluateTimeOfDayBoost(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|uuid',
            'timestamp' => 'nullable|date',
        ]);

        $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $validated['subscriber_id'])->firstOrFail();
        $timestamp = isset($validated['timestamp']) ? Carbon::parse($validated['timestamp']) : null;

        $result = $this->boostService->evaluateAndApplyBoost($subscriber, $timestamp);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    public function formatVendorVsa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vendor' => 'required|string|in:mikrotik,cisco,juniper,huawei',
            'rate_limit' => 'required|string',
            'pool' => 'nullable|string',
        ]);

        $replyAttrs = $this->vsaService->formatVendorReply(
            vendor: $validated['vendor'],
            rateLimit: $validated['rate_limit'],
            pool: $validated['pool'] ?? 'pppoe_active_pool'
        );

        return response()->json([
            'status' => 'success',
            'vendor' => $validated['vendor'],
            'attributes' => $replyAttrs,
        ]);
    }

    public function ingestNetflow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'flows' => 'required|array|min:1',
            'flows.*.tenant_id' => 'required|integer',
            'flows.*.src_ip' => 'required|ip',
            'flows.*.dst_ip' => 'required|ip',
            'flows.*.protocol' => 'required|string',
            'flows.*.src_port' => 'required|integer',
            'flows.*.dst_port' => 'required|integer',
            'flows.*.bytes' => 'required|integer',
            'flows.*.packets' => 'required|integer',
            'flows.*.subscriber_id' => 'nullable|uuid',
        ]);

        $count = $this->netflowService->ingestFlows($validated['flows']);

        return response()->json([
            'status' => 'success',
            'ingested_flows' => $count,
        ], 201);
    }

    public function getTrafficBreakdown(Request $request): JsonResponse
    {
        $subId = $request->query('subscriber_id');
        $hours = (int) ($request->query('hours', 24));

        $breakdown = $this->netflowService->getTrafficBreakdown(
            subscriberId: is_string($subId) ? $subId : null,
            hours: $hours
        );

        return response()->json([
            'status' => 'success',
            'data' => $breakdown,
        ]);
    }

    public function allocateCgnat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|uuid',
            'private_ip' => 'required|ip',
            'public_ip' => 'required|ip',
            'port_count' => 'nullable|integer',
        ]);

        $binding = $this->cgnatService->allocatePortBlock(
            subscriberId: $validated['subscriber_id'],
            tenantId: 1,
            privateIp: $validated['private_ip'],
            publicIp: $validated['public_ip'],
            portCount: (int) ($validated['port_count'] ?? 1000)
        );

        return response()->json([
            'status' => 'success',
            'data' => $binding,
        ], 201);
    }

    public function correlateCgnat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'public_ip' => 'required|ip',
            'port' => 'required|integer',
            'timestamp' => 'required|date',
        ]);

        $correlation = $this->cgnatService->correlateSession(
            publicIp: $validated['public_ip'],
            port: (int) $validated['port'],
            timestamp: Carbon::parse($validated['timestamp'])
        );

        if (! $correlation) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'No active CGNAT port translation found for target public IP and port at timestamp.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $correlation,
        ]);
    }
}
