<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditChainVerificationService;
use App\Services\Operations\DisasterRecoveryDrillService;
use App\Services\Payment\DirectDebitClearingService;
use App\Services\Radius\AnycastRadiusTopologyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * P2 Batch 3: High Availability, Banking Clearing, Anycast Topology & Disaster Recovery Controller
 */
class HaAndBankingController extends Controller
{
    public function __construct(
        protected DirectDebitClearingService $clearingService,
        protected AnycastRadiusTopologyService $anycastService,
        protected DisasterRecoveryDrillService $drService,
        protected AuditChainVerificationService $auditVerificationService
    ) {}

    /**
     * P2-6: Generate BEFTN / NPSB Direct Debit Clearing Batch
     */
    public function generateClearingBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'clearing_type' => 'required|string|in:BEFTN,NPSB,RTGS',
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'required|uuid',
            'originator_info' => 'required|array',
            'originator_info.bank_code' => 'required|string|max:20',
            'originator_info.routing_no' => 'required|string|max:20',
            'originator_info.account_no' => 'required|string|max:50',
            'originator_info.name' => 'required|string|max:150',
            'settlement_date' => 'nullable|date',
        ]);

        $batch = $this->clearingService->generateBatch(
            $validated['tenant_id'] ?? 1,
            $validated['clearing_type'],
            $validated['invoice_ids'],
            $validated['originator_info'],
            $validated['settlement_date'] ?? null
        );

        return response()->json([
            'message' => 'Direct debit clearing batch generated successfully',
            'batch' => $batch,
        ], 201);
    }

    /**
     * P2-6: Export Direct Debit ISO 20022 XML Batch File
     */
    public function exportClearingBatch(string $batchId): Response
    {
        $payload = $this->clearingService->exportBatchFile($batchId);

        return response($payload, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"beftn-batch-{$batchId}.xml\"",
        ]);
    }

    /**
     * P2-6: Process Settlement Return File / Acknowledgment
     */
    public function processSettlementReturn(Request $request, string $batchId): JsonResponse
    {
        $validated = $request->validate([
            'returns' => 'required|array|min:1',
            'returns.*.instruction_id' => 'required|string',
            'returns.*.return_code' => 'required|string',
            'returns.*.failure_reason' => 'nullable|string',
        ]);

        $summary = $this->clearingService->processSettlementReturn($batchId, $validated['returns']);

        return response()->json([
            'message' => 'Direct debit clearing returns processed successfully',
            'summary' => $summary,
        ]);
    }

    /**
     * P2-9: Register Anycast Node
     */
    public function registerAnycastNode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'node_code' => 'required|string|max:32',
            'region' => 'required|string|max:64',
            'pop_name' => 'required|string|max:100',
            'vip_ipv4' => 'required|ip',
            'physical_ip' => 'required|ip',
            'bgp_asn' => 'nullable|integer',
            'bgp_community' => 'nullable|string',
            'weight' => 'nullable|integer',
        ]);

        $node = $this->anycastService->registerNode($validated);

        return response()->json([
            'message' => 'Anycast node registered successfully',
            'node' => $node,
        ], 201);
    }

    /**
     * P2-9: Ingest Health Check Telemetry for Anycast Node
     */
    public function recordAnycastHealth(Request $request, string $nodeCode): JsonResponse
    {
        $validated = $request->validate([
            'auth_latency_ms' => 'required|numeric|min:0',
            'acct_latency_ms' => 'required|numeric|min:0',
            'replication_lag_seconds' => 'required|numeric|min:0',
            'bgp_session_up' => 'required|boolean',
            'packet_loss_pct' => 'nullable|numeric|min:0|max:100',
            'details' => 'nullable|array',
        ]);

        $healthCheck = $this->anycastService->recordHealthCheck($nodeCode, $validated);

        return response()->json([
            'message' => 'Anycast health check recorded',
            'health_check' => $healthCheck,
        ]);
    }

    /**
     * P2-9: Manual Anycast Node Drain
     */
    public function drainAnycastNode(Request $request, string $nodeCode): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $node = $this->anycastService->drainNode($nodeCode, $validated['reason']);

        return response()->json([
            'message' => "Node {$nodeCode} has been withdrawn and set to draining",
            'node' => $node,
        ]);
    }

    /**
     * P2-9: Re-announce Anycast Node BGP Community
     */
    public function announceAnycastNode(string $nodeCode): JsonResponse
    {
        $node = $this->anycastService->announceNode($nodeCode);

        return response()->json([
            'message' => "Node {$nodeCode} BGP route announced successfully",
            'node' => $node,
        ]);
    }

    /**
     * P2-9: Retrieve Global Anycast Topology Overview
     */
    public function getAnycastTopology(): JsonResponse
    {
        $topology = $this->anycastService->getTopologyStatus();

        return response()->json($topology);
    }

    /**
     * P2-10: Execute Automated Disaster Recovery Drill
     */
    public function executeDrill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'drill_type' => 'required|string',
            'initiated_by' => 'required|string',
            'primary_region' => 'nullable|string',
            'secondary_region' => 'nullable|string',
            'simulated_rto_seconds' => 'nullable|numeric|min:0',
            'simulated_rpo_seconds' => 'nullable|numeric|min:0',
        ]);

        $drill = $this->drService->executeDrill(
            $validated['drill_type'],
            $validated['initiated_by'],
            $validated
        );

        return response()->json([
            'message' => 'Disaster recovery failover drill completed',
            'drill' => $drill,
        ], 201);
    }

    /**
     * P2-10: List Historical DR Drills
     */
    public function listDrills(): JsonResponse
    {
        $drills = $this->drService->getDrillHistory();

        return response()->json([
            'total' => $drills->count(),
            'drills' => $drills,
        ]);
    }

    /**
     * P2-11: Verify Tamper-Evident Hash Chain on Billing Audit Log
     */
    public function verifyAuditChain(Request $request): JsonResponse
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $result = $this->auditVerificationService->verifyChain(
            is_string($startDate) ? $startDate : null,
            is_string($endDate) ? $endDate : null
        );

        return response()->json($result);
    }
}
