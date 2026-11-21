<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AiCopilotController;
use App\Http\Controllers\Api\V1\HotspotVoucherController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\IpPoolController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\MfsWebhookController;
use App\Http\Controllers\Api\V1\SubscriberController;
use App\Http\Controllers\Api\V1\TenantController;
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
