<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Billing\SelfSignupService;
use App\Services\Compliance\EkycVerificationService;
use App\Services\Crm\LeadManagementService;
use App\Services\Hotspot\CaptivePortalBuilderService;
use App\Services\Reseller\ResellerWalletService;
use App\Services\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Phase 2 (P1 Batch 3): Commercial Onboarding, CRM & Reseller Hierarchy Controller
 */
class CommercialAndResellerController extends Controller
{
    public function __construct(
        protected LeadManagementService $leadService,
        protected EkycVerificationService $ekycService,
        protected ResellerWalletService $resellerService,
        protected SelfSignupService $signupService,
        protected CaptivePortalBuilderService $portalService,
        protected TenantContext $tenantContext
    ) {}

    // --- P1-11: Lead Management & Fiber Feasibility ---

    public function createLead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'name' => 'required|string',
            'phone' => 'required|string',
            'email' => 'nullable|email',
            'address' => 'required|string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $lead = $this->leadService->createLead($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $lead,
        ], 201);
    }

    public function checkFeasibility(Request $request, string $leadId): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $check = $this->leadService->checkFeasibility($leadId, (float) $validated['lat'], (float) $validated['lng']);

        return response()->json([
            'status' => 'success',
            'data' => $check,
        ]);
    }

    public function generateQuote(Request $request, string $leadId): JsonResponse
    {
        $validated = $request->validate([
            'tariff_id' => 'nullable|integer',
            'installation_charge_bdt' => 'nullable|numeric|min:0',
            'monthly_recurring_bdt' => 'nullable|numeric|min:0',
        ]);

        $quote = $this->leadService->generateQuote(
            $leadId,
            isset($validated['tariff_id']) ? (int) $validated['tariff_id'] : null,
            (float) ($validated['installation_charge_bdt'] ?? 1500.0),
            isset($validated['monthly_recurring_bdt']) ? (float) $validated['monthly_recurring_bdt'] : null
        );

        return response()->json([
            'status' => 'success',
            'data' => $quote,
        ], 201);
    }

    public function convertLead(Request $request, string $leadId): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'tariff_id' => 'nullable|integer',
        ]);

        $subscriber = $this->leadService->convertLeadToSubscriber(
            $leadId,
            $validated['username'],
            $validated['password'],
            isset($validated['tariff_id']) ? (int) $validated['tariff_id'] : null
        );

        return response()->json([
            'status' => 'success',
            'data' => $subscriber,
        ]);
    }

    // --- P1-12: Digital eKYC / eCAF Submissions ---

    public function submitEcaf(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'subscriber_id' => 'nullable|uuid',
            'nid_number' => 'required|string',
            'dob' => 'required|date',
            'full_name_bangla' => 'nullable|string',
            'full_name_english' => 'required|string',
            'father_name' => 'nullable|string',
            'mother_name' => 'nullable|string',
            'permanent_address' => 'nullable|string',
            'present_address' => 'required|string',
            'nid_front_path' => 'nullable|string',
            'nid_back_path' => 'nullable|string',
            'live_selfie_path' => 'nullable|string',
            'customer_signature_path' => 'nullable|string',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $ecaf = $this->ekycService->submitEcaf($tenantId, $validated);

        return response()->json([
            'status' => 'success',
            'data' => $ecaf,
        ], 201);
    }

    public function verifyEcaf(string $ecafId): JsonResponse
    {
        $result = $this->ekycService->verifyIdentity($ecafId);

        return response()->json($result, ($result['verified'] ?? false) ? 200 : 422);
    }

    public function exportBtrcAudit(string $ecafId): JsonResponse
    {
        $export = $this->ekycService->exportBtrcAuditRecord($ecafId);

        return response()->json([
            'status' => 'success',
            'data' => $export,
        ]);
    }

    // --- P1-15: Reseller Prepaid Wallets & Splits ---

    public function getResellerWallet(Request $request, int $resellerId): JsonResponse
    {
        $tenantId = (int) ($request->query('tenant_id', $this->tenantContext->getTenantId() ?? 1));
        $wallet = $this->resellerService->getOrCreateWallet($tenantId, $resellerId);

        return response()->json([
            'status' => 'success',
            'data' => $wallet,
        ]);
    }

    public function topupResellerWallet(Request $request, string $walletId): JsonResponse
    {
        $validated = $request->validate([
            'amount_bdt' => 'required|numeric|min:1',
            'reference_id' => 'required|string',
            'description' => 'nullable|string',
        ]);

        $tx = $this->resellerService->topupWallet(
            $walletId,
            (float) $validated['amount_bdt'],
            $validated['reference_id'],
            $validated['description'] ?? 'Prepaid Wallet Top-up'
        );

        return response()->json([
            'status' => 'success',
            'data' => $tx,
        ]);
    }

    public function debitResellerActivation(Request $request, string $walletId): JsonResponse
    {
        $validated = $request->validate([
            'plan_amount_bdt' => 'required|numeric|min:1',
            'subscriber_id' => 'required|string',
        ]);

        $tx = $this->resellerService->debitForActivation(
            $walletId,
            (float) $validated['plan_amount_bdt'],
            $validated['subscriber_id']
        );

        return response()->json([
            'status' => 'success',
            'data' => $tx,
        ]);
    }

    public function getResellerTransactions(string $walletId): JsonResponse
    {
        $txs = $this->resellerService->getTransactionHistory($walletId);

        return response()->json([
            'status' => 'success',
            'data' => $txs,
        ]);
    }

    // --- P1-16: Online Self-Signup & Promotions ---

    public function applyPromotion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'promo_code' => 'required|string',
            'plan_amount_bdt' => 'required|numeric|min:1',
            'tenant_id' => 'nullable|integer',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $result = $this->signupService->applyPromotion($validated['promo_code'], (float) $validated['plan_amount_bdt'], $tenantId);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    public function selfSignup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'nullable|integer',
            'username' => 'required|string',
            'password' => 'required|string',
            'tariff_id' => 'required|integer',
            'promo_code' => 'nullable|string',
        ]);

        $tenantId = (int) ($validated['tenant_id'] ?? $this->tenantContext->getTenantId() ?? 1);
        $result = $this->signupService->registerSelfSubscriber($tenantId, $validated);

        return response()->json($result, 201);
    }

    // --- P1-17: Dynamic Captive Portal & WISPr ---

    public function renderCaptivePortal(Request $request): Response
    {
        $brandName = (string) $request->query('brand_name', 'Carrier Ultra');
        $html = $this->portalService->renderPortalHtml($brandName);

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    public function getWisprXml(Request $request): Response
    {
        $loginUrl = (string) $request->query('login_url', 'http://hotspot.carrier.net/api/v1/hotspot/login');
        $xml = $this->portalService->generateWisprResponse($loginUrl);

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    public function authenticateVoucher(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'voucher_code' => 'required|string',
            'pin' => 'nullable|string',
            'mac_address' => 'nullable|string',
        ]);

        $result = $this->portalService->authenticateVoucher(
            $validated['voucher_code'],
            $validated['pin'] ?? null,
            $validated['mac_address'] ?? null
        );

        return response()->json($result, ($result['authorized'] ?? false) ? 200 : 401);
    }
}
