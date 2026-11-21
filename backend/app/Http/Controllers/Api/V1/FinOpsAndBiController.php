<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Analytics\ExecutiveBiService;
use App\Services\Billing\BankReconciliationService;
use App\Services\Billing\CashdeskService;
use App\Services\Notification\OmnichannelAlertService;
use App\Services\Tenant\TenantContext;
use App\Services\Webhook\OutboundWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 2 (P1 Batch 4): FinOps, Cashdesk POS, Omnichannel Comms, Webhooks & Executive BI Controller
 */
class FinOpsAndBiController extends Controller
{
    public function __construct(
        protected BankReconciliationService $bankService,
        protected CashdeskService $cashService,
        protected OmnichannelAlertService $alertService,
        protected OutboundWebhookService $webhookService,
        protected ExecutiveBiService $biService,
        protected TenantContext $tenantContext
    ) {}

    // --- P1-4: Bank Statement Reconciliation ---

    public function uploadBankStatement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'statement_date' => 'required|date',
            'lines' => 'required|array',
            'lines.*.amount_bdt' => 'required|numeric',
            'lines.*.reference_number' => 'nullable|string',
            'lines.*.description' => 'nullable|string',
            'lines.*.date' => 'nullable|date',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $statement = $this->bankService->ingestStatement(
            $tenantId,
            $validated['bank_name'],
            $validated['account_number'],
            $validated['statement_date'],
            $validated['lines']
        );

        return response()->json([
            'status' => 'success',
            'data' => $statement,
        ], 201);
    }

    public function reconcileBankLine(Request $request, string $lineId): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => 'nullable|uuid',
        ]);

        $result = $this->bankService->reconcileLine($lineId, $validated['invoice_id'] ?? null);

        return response()->json($result, ($result['reconciled'] ?? false) ? 200 : 422);
    }

    public function autoReconcileStatement(string $statementId): JsonResponse
    {
        $result = $this->bankService->autoReconcileStatement($statementId);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    // --- P1-5: Cashier POS & Cash Drawer Shifts ---

    public function openCashShift(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'cashier_user_id' => 'required|integer',
            'starting_cash_bdt' => 'nullable|numeric|min:0',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $shift = $this->cashService->openShift(
            $tenantId,
            (int) $validated['cashier_user_id'],
            (float) ($validated['starting_cash_bdt'] ?? 0.0)
        );

        return response()->json([
            'status' => 'success',
            'data' => $shift,
        ], 201);
    }

    public function collectCashPayment(Request $request, string $shiftId): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|uuid',
            'invoice_id' => 'required|uuid',
            'amount_bdt' => 'required|numeric|min:1',
        ]);

        $collection = $this->cashService->collectCashPayment(
            $shiftId,
            $validated['subscriber_id'],
            $validated['invoice_id'],
            (float) $validated['amount_bdt']
        );

        return response()->json([
            'status' => 'success',
            'data' => $collection,
        ], 201);
    }

    public function closeCashShift(Request $request, string $shiftId): JsonResponse
    {
        $validated = $request->validate([
            'closing_cash_bdt' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $shift = $this->cashService->closeShift(
            $shiftId,
            (float) $validated['closing_cash_bdt'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'data' => $shift,
        ]);
    }

    // --- P1-18: Omnichannel Notifications ---

    public function sendWhatsApp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'template_name' => 'required|string',
            'parameters' => 'nullable|array',
        ]);

        $result = $this->alertService->sendWhatsAppMessage(
            $validated['phone'],
            $validated['template_name'],
            $validated['parameters'] ?? []
        );

        return response()->json($result);
    }

    public function sendTelegram(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chat_id' => 'required|string',
            'message' => 'required|string',
        ]);

        $result = $this->alertService->sendTelegramAlert($validated['chat_id'], $validated['message']);

        return response()->json($result);
    }

    // --- P1-19: Outbound Webhook Subscriptions ---

    public function createWebhookSubscription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'target_url' => 'required|url',
            'events' => 'required|array',
            'secret' => 'nullable|string',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $subscription = $this->webhookService->subscribe(
            $tenantId,
            $validated['target_url'],
            $validated['events'],
            $validated['secret'] ?? null
        );

        return response()->json([
            'status' => 'success',
            'data' => $subscription,
        ], 201);
    }

    public function dispatchWebhookEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'event_type' => 'required|string',
            'payload' => 'required|array',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $results = $this->webhookService->dispatchEvent($tenantId, $validated['event_type'], $validated['payload']);

        return response()->json([
            'status' => 'success',
            'deliveries' => $results,
        ]);
    }

    // --- P1-20: Executive BI Telemetry Overview ---

    public function getExecutiveBiOverview(Request $request): JsonResponse
    {
        $tenantId = (int) ($request->query('tenant_id', $this->tenantContext->getTenantId() ?? 1));
        $overview = $this->biService->getExecutiveOverview($tenantId);

        return response()->json([
            'status' => 'success',
            'data' => $overview,
        ]);
    }
}
