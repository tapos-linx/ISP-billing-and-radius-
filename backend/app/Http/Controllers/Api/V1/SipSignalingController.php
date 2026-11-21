<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Voip\SipSignalingBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Release 2.0 (Epic 2): SIP Signaling Controller (FreeSWITCH ESL / Kamailio API)
 */
class SipSignalingController extends Controller
{
    public function __construct(
        protected SipSignalingBridgeService $signalingService
    ) {}

    /**
     * Authorize SIP INVITE: Validate balance and reserve credit
     */
    public function authorizeInvite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'call_id' => 'required|string',
            'caller_e164' => 'required|string',
            'callee_e164' => 'required|string',
            'subscriber_id' => 'nullable|uuid',
            'trunk_id' => 'nullable|uuid',
        ]);

        $result = $this->signalingService->authorizeInvite(
            $validated['tenant_id'] ?? 1,
            $validated
        );

        $status = $result['authorized'] ? 200 : $result['sip_code'];

        return response()->json($result, $status);
    }

    /**
     * Process SIP BYE: Ingest CDR and reconcile reserved credit
     */
    public function processBye(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'call_id' => 'required|string',
            'caller_e164' => 'required|string',
            'callee_e164' => 'required|string',
            'started_at' => 'required|date',
            'answered_at' => 'nullable|date',
            'ended_at' => 'required|date',
            'disposition' => 'nullable|string',
            'trunk_id' => 'nullable|uuid',
        ]);

        $summary = $this->signalingService->processBye(
            $validated['tenant_id'] ?? 1,
            $validated
        );

        return response()->json([
            'message' => 'SIP call tear-down processed and rated successfully',
            'summary' => $summary,
        ]);
    }

    /**
     * Release Credit Reservation on Cancel / Failure
     */
    public function releaseReservation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'call_id' => 'required|string',
        ]);

        $result = $this->signalingService->releaseReservation($validated['call_id']);

        return response()->json($result);
    }
}
