<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AiCopilotController;
use App\Http\Controllers\Api\V1\BillingLifecycleController;
use App\Http\Controllers\Api\V1\CardPaymentController;
use App\Http\Controllers\Api\V1\CommercialAndResellerController;
use App\Http\Controllers\Api\V1\FieldAndCpeController;
use App\Http\Controllers\Api\V1\FinOpsAndBiController;
use App\Http\Controllers\Api\V1\HelpdeskController;
use App\Http\Controllers\Api\V1\HotspotVoucherController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\IpdrController;
use App\Http\Controllers\Api\V1\IpPoolController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\MfsWebhookController;
use App\Http\Controllers\Api\V1\NetworkOperationsController;
use App\Http\Controllers\Api\V1\SubscriberController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TrafficAndAaaController;
use App\Http\Middleware\VerifyMfsReplayProtection;
use App\Jobs\ExpireOverdueSubscribersJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Stage 8, 12 & Phase 4/6 Complete Carrier API Routes
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        DB::select('SELECT 1');
        $redisPing = Redis::ping();

        return response()->json([
            'status' => 'ok',
            'citus' => 'connected',
            'redis' => (string) $redisPing,
        ]);
    });

    // Phase 4: Prometheus SLA Scrape Endpoint
    Route::get('/metrics', [MetricsController::class, 'metrics']);

    // MFS Fintech Webhooks (HMAC-SHA256 + Redis SETNX Nonce & Idempotency)
    Route::middleware([VerifyMfsReplayProtection::class])->group(function (): void {
        Route::post('/webhooks/bkash', [MfsWebhookController::class, 'handleBkash']);
        Route::post('/webhooks/nagad', [MfsWebhookController::class, 'handleNagad']);
    });

    // Cloud Scheduler 00:01 AM Dhaka Expiry Job
    Route::post('/jobs/expire-overdue', function () {
        ExpireOverdueSubscribersJob::dispatch();

        return response()->json(['status' => 'queued']);
    });

    // Gemini API NOC Copilot
    Route::post('/ai/diagnose', [AiCopilotController::class, 'diagnose']);

    // Release 2.0 (Epic 1): Wi-Fi Hotspot Voucher Management Endpoints
    Route::prefix('hotspot')->group(function (): void {
        Route::get('/profiles', [HotspotVoucherController::class, 'indexProfiles']);
        Route::post('/profiles', [HotspotVoucherController::class, 'createProfile']);
        Route::post('/vouchers/generate', [HotspotVoucherController::class, 'generateBatch']);
        Route::get('/vouchers/{code}', [HotspotVoucherController::class, 'getVoucher']);
        Route::get('/vouchers/{code}/receipt', [HotspotVoucherController::class, 'renderReceipt']);
        Route::post('/vouchers/auth-verify', [HotspotVoucherController::class, 'authVerify']);
        Route::post('/vouchers/{code}/consume', [HotspotVoucherController::class, 'consumeQuota']);
        Route::get('/portal', [CommercialAndResellerController::class, 'renderCaptivePortal']);
        Route::get('/wispr', [CommercialAndResellerController::class, 'getWisprXml']);
        Route::post('/login', [CommercialAndResellerController::class, 'authenticateVoucher']);
    });

    // Release 2.0 (Epic 3): ClickHouse BTRC 2-Year IPDR Telemetry Endpoints
    Route::prefix('ipdr')->group(function (): void {
        Route::get('/status', [IpdrController::class, 'status']);
        Route::post('/ingest', [IpdrController::class, 'ingest']);
        Route::get('/search', [IpdrController::class, 'search']);
    });

    // P0: Billing Lifecycle (Pro-Rata, Credit Notes, Refunds & Dunning)
    Route::prefix('billing')->group(function (): void {
        Route::post('/plan-change/calculate', [BillingLifecycleController::class, 'calculatePlanChange']);
        Route::post('/plan-change/apply', [BillingLifecycleController::class, 'applyPlanChange']);
        Route::post('/invoices/{id}/credit-note', [BillingLifecycleController::class, 'issueCreditNote']);
        Route::post('/invoices/{id}/void', [BillingLifecycleController::class, 'voidInvoice']);
        Route::post('/payments/{id}/refund', [BillingLifecycleController::class, 'processRefund']);
        Route::post('/dunning/process', [BillingLifecycleController::class, 'processDunning']);
        Route::post('/subscribers/{id}/vacation-mode', [BillingLifecycleController::class, 'setVacationMode']);
    });

    // P0-7: Universal Card Payment Gateway Endpoints
    Route::prefix('payments/card')->group(function (): void {
        Route::post('/initiate', [CardPaymentController::class, 'initiate']);
        Route::post('/sslcommerz/ipn', [CardPaymentController::class, 'handleSslCommerzIpn']);
        Route::post('/stripe/webhook', [CardPaymentController::class, 'handleStripeWebhook']);
    });

    // P0-9 to P0-12: Network Operations, MikroTik, OLT, Polling & IPAM
    Route::prefix('network')->group(function (): void {
        Route::get('/mikrotik/sessions', [NetworkOperationsController::class, 'getMikrotikSessions']);
        Route::post('/mikrotik/backup', [NetworkOperationsController::class, 'triggerMikrotikBackup']);
        Route::get('/olts/{id}/autofind', [NetworkOperationsController::class, 'discoverOlts']);
        Route::post('/olts/{id}/provision', [NetworkOperationsController::class, 'provisionOnu']);
        Route::get('/devices/poll', [NetworkOperationsController::class, 'pollDevices']);
        Route::get('/ipam/split', [NetworkOperationsController::class, 'splitSubnet']);
        Route::get('/ipam/abuse-lookup', [NetworkOperationsController::class, 'lookupAbuse']);
    });

    // P0-13: Helpdesk Ticketing Endpoints
    Route::prefix('helpdesk')->group(function (): void {
        Route::get('/tickets', [HelpdeskController::class, 'index']);
        Route::post('/tickets', [HelpdeskController::class, 'store']);
        Route::get('/tickets/{id}', [HelpdeskController::class, 'show']);
        Route::post('/tickets/{id}/comments', [HelpdeskController::class, 'addComment']);
        Route::post('/tickets/{id}/resolve', [HelpdeskController::class, 'resolve']);
        Route::get('/canned-replies', [HelpdeskController::class, 'cannedReplies']);
    });

    // Phase 2 (P1 Batch 1): AAA, Traffic & Bandwidth Engineering Endpoints
    Route::prefix('traffic')->group(function (): void {
        Route::post('/fup/check', [TrafficAndAaaController::class, 'checkFup']);
        Route::post('/fup/topup', [TrafficAndAaaController::class, 'purchaseTopup']);
        Route::post('/time-of-day/evaluate', [TrafficAndAaaController::class, 'evaluateTimeOfDayBoost']);
        Route::post('/vsa/format', [TrafficAndAaaController::class, 'formatVendorVsa']);
        Route::post('/netflow/ingest', [TrafficAndAaaController::class, 'ingestNetflow']);
        Route::get('/netflow/breakdown', [TrafficAndAaaController::class, 'getTrafficBreakdown']);
        Route::post('/cgnat/allocate', [TrafficAndAaaController::class, 'allocateCgnat']);
        Route::get('/cgnat/correlate', [TrafficAndAaaController::class, 'correlateCgnat']);
    });

    // Phase 2 (P1 Batch 2): CPE Management & TR-069 Endpoints
    Route::prefix('cpe')->group(function (): void {
        Route::post('/reboot', [FieldAndCpeController::class, 'rebootCpe']);
        Route::post('/wifi', [FieldAndCpeController::class, 'configureWifi']);
        Route::post('/pppoe', [FieldAndCpeController::class, 'provisionWanPppoe']);
        Route::post('/alarms/event', [FieldAndCpeController::class, 'handleAlarmEvent']);
        Route::get('/alarms/active', [FieldAndCpeController::class, 'getActiveAlarms']);
        Route::post('/alarms/suppress', [FieldAndCpeController::class, 'suppressAlarms']);
        Route::post('/alarms/{id}/resolve', [FieldAndCpeController::class, 'resolveParentAlarm']);
    });

    // Phase 2 (P1 Batch 2): Field Workforce & Inventory Endpoints
    Route::prefix('field-ops')->group(function (): void {
        Route::post('/technicians', [FieldAndCpeController::class, 'registerTechnician']);
        Route::post('/technicians/{id}/location', [FieldAndCpeController::class, 'updateTechnicianLocation']);
        Route::post('/work-orders', [FieldAndCpeController::class, 'createWorkOrder']);
        Route::post('/work-orders/{id}/assign', [FieldAndCpeController::class, 'assignWorkOrder']);
        Route::post('/work-orders/{id}/complete', [FieldAndCpeController::class, 'completeWorkOrder']);
        Route::post('/inventory/warehouses', [FieldAndCpeController::class, 'createWarehouse']);
        Route::post('/inventory/items', [FieldAndCpeController::class, 'receiveItem']);
        Route::post('/inventory/items/{id}/assign', [FieldAndCpeController::class, 'assignItemToSubscriber']);
        Route::post('/inventory/items/{id}/return', [FieldAndCpeController::class, 'returnItemToWarehouse']);
        Route::get('/inventory/warehouses/{id}/stock', [FieldAndCpeController::class, 'getWarehouseStock']);
    });

    // Phase 2 (P1 Batch 3): Commercial CRM, eKYC, Reseller & Self-Signup Endpoints
    Route::prefix('crm')->group(function (): void {
        Route::post('/leads', [CommercialAndResellerController::class, 'createLead']);
        Route::post('/leads/{id}/feasibility', [CommercialAndResellerController::class, 'checkFeasibility']);
        Route::post('/leads/{id}/quote', [CommercialAndResellerController::class, 'generateQuote']);
        Route::post('/leads/{id}/convert', [CommercialAndResellerController::class, 'convertLead']);
    });

    Route::prefix('compliance/ecaf')->group(function (): void {
        Route::post('/', [CommercialAndResellerController::class, 'submitEcaf']);
        Route::post('/{id}/verify', [CommercialAndResellerController::class, 'verifyEcaf']);
        Route::get('/{id}/export', [CommercialAndResellerController::class, 'exportBtrcAudit']);
    });

    Route::prefix('reseller')->group(function (): void {
        Route::get('/wallets/{resellerId}', [CommercialAndResellerController::class, 'getResellerWallet']);
        Route::post('/wallets/{walletId}/topup', [CommercialAndResellerController::class, 'topupResellerWallet']);
        Route::post('/wallets/{walletId}/activate', [CommercialAndResellerController::class, 'debitResellerActivation']);
        Route::get('/wallets/{walletId}/transactions', [CommercialAndResellerController::class, 'getResellerTransactions']);
    });

    Route::prefix('self-care')->group(function (): void {
        Route::post('/promotions/apply', [CommercialAndResellerController::class, 'applyPromotion']);
        Route::post('/signup', [CommercialAndResellerController::class, 'selfSignup']);
    });

    // Phase 2 (P1 Batch 4): FinOps, Cashdesk POS, Omnichannel Comms, Webhooks & Executive BI
    Route::prefix('finops')->group(function (): void {
        Route::post('/bank-statements', [FinOpsAndBiController::class, 'uploadBankStatement']);
        Route::post('/bank-statement-lines/{id}/reconcile', [FinOpsAndBiController::class, 'reconcileBankLine']);
        Route::post('/bank-statements/{id}/auto-reconcile', [FinOpsAndBiController::class, 'autoReconcileStatement']);
    });

    Route::prefix('cashdesk')->group(function (): void {
        Route::post('/shifts/open', [FinOpsAndBiController::class, 'openCashShift']);
        Route::post('/shifts/{id}/collect', [FinOpsAndBiController::class, 'collectCashPayment']);
        Route::post('/shifts/{id}/close', [FinOpsAndBiController::class, 'closeCashShift']);
    });

    Route::prefix('notifications')->group(function (): void {
        Route::post('/whatsapp', [FinOpsAndBiController::class, 'sendWhatsApp']);
        Route::post('/telegram', [FinOpsAndBiController::class, 'sendTelegram']);
    });

    Route::prefix('webhooks')->group(function (): void {
        Route::post('/subscriptions', [FinOpsAndBiController::class, 'createWebhookSubscription']);
        Route::post('/dispatch', [FinOpsAndBiController::class, 'dispatchWebhookEvent']);
    });

    Route::prefix('analytics')->group(function (): void {
        Route::get('/bi-overview', [FinOpsAndBiController::class, 'getExecutiveBiOverview']);
    });

    // Phase 6: Multi-Tenant Scoped & RBAC Protected Endpoints
    Route::middleware(['auth.token'])->group(function (): void {
        // Subscribers (Resellers, Admins, NOC)
        Route::get('/subscribers', [SubscriberController::class, 'index']);
        Route::get('/subscribers/{id}', [SubscriberController::class, 'show']);
        Route::post('/subscribers', [SubscriberController::class, 'store']);
        Route::put('/subscribers/{id}', [SubscriberController::class, 'update']);

        // Invoices (Billing, Admins, Resellers)
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);

        // IP Pools allocated to Tenant
        Route::get('/ip-pools', [IpPoolController::class, 'index']);

        // Tenants Management (SuperAdmin only)
        Route::middleware(['role:SuperAdmin'])->group(function (): void {
            Route::get('/tenants', [TenantController::class, 'index']);
            Route::get('/tenants/{id}', [TenantController::class, 'show']);
        });
    });
});
