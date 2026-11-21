<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Services\Billing\DunningPipelineService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\PlanChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P0-4, P0-5, P0-6: Billing Lifecycle, Pro-Rata, Credit Notes, Refunds & Dunning Controller
 */
class BillingLifecycleController extends Controller
{
    public function __construct(
        protected PlanChangeService $planChangeService,
        protected InvoiceLifecycleService $invoiceLifecycleService,
        protected DunningPipelineService $dunningPipelineService
    ) {}

    public function calculatePlanChange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|uuid',
            'new_tariff_id' => 'required|integer|exists:tariffs,tariff_id',
        ]);

        $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $validated['subscriber_id'])->firstOrFail();
        $tariff = Tariff::withoutGlobalScopes()->findOrFail($validated['new_tariff_id']);

        $calculation = $this->planChangeService->calculateProRata($subscriber, $tariff);

        return response()->json([
            'status' => 'success',
            'data' => $calculation,
        ]);
    }

    public function applyPlanChange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_id' => 'required|uuid',
            'new_tariff_id' => 'required|integer|exists:tariffs,tariff_id',
        ]);

        $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $validated['subscriber_id'])->firstOrFail();
        $result = $this->planChangeService->applyPlanChange($subscriber, (int) $validated['new_tariff_id']);

        return response()->json([
            'status' => 'success',
            'message' => 'Plan change applied successfully.',
            'data' => $result,
        ]);
    }

    public function issueCreditNote(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ]);

        $invoice = Invoice::withoutGlobalScopes()->where('invoice_id', $id)->firstOrFail();
        $creditNote = $this->invoiceLifecycleService->issueCreditNote($invoice, (float) $validated['amount'], $validated['reason']);

        return response()->json([
            'status' => 'success',
            'message' => 'Credit note issued successfully.',
            'data' => $creditNote,
        ], 201);
    }

    public function voidInvoice(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $invoice = Invoice::withoutGlobalScopes()->where('invoice_id', $id)->firstOrFail();
        $voidedInvoice = $this->invoiceLifecycleService->voidInvoice($invoice, $validated['reason']);

        return response()->json([
            'status' => 'success',
            'message' => 'Invoice voided successfully.',
            'data' => $voidedInvoice,
        ]);
    }

    public function processRefund(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'method' => 'nullable|string|in:bkash,nagad,sslcommerz,stripe,cash',
        ]);

        $payment = Payment::withoutGlobalScopes()->where('payment_id', $id)->firstOrFail();
        $refund = $this->invoiceLifecycleService->processRefund(
            $payment,
            (float) $validated['amount'],
            $validated['reason'],
            $validated['method'] ?? 'bkash'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Refund processed successfully.',
            'data' => $refund,
        ], 201);
    }

    public function processDunning(): JsonResponse
    {
        $results = $this->dunningPipelineService->processDunning();

        return response()->json([
            'status' => 'success',
            'message' => 'Dunning pipeline executed successfully.',
            'data' => $results,
        ]);
    }

    public function setVacationMode(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'duration_days' => 'required|integer|min:15|max:90',
            'reason' => 'nullable|string|max:255',
        ]);

        $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $id)->firstOrFail();
        $result = $this->dunningPipelineService->setVacationMode($subscriber, (int) $validated['duration_days'], $validated['reason'] ?? null);

        return response()->json([
            'status' => 'success',
            'message' => 'Vacation mode activated successfully.',
            'data' => $result,
        ]);
    }
}
