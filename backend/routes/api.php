<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AiCopilotController;
use App\Http\Controllers\Api\V1\MfsWebhookController;
use App\Http\Middleware\VerifyMfsReplayProtection;
use App\Jobs\ExpireOverdueSubscribersJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Stage 8 & 12 Complete API & Customer Portal Routes (Matches openapi.yaml)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function (): void {
    Route::get('/health', function () {
        DB::select('SELECT 1');
        $redisPing = Redis::ping();
        return response()->json([
            'status' => 'ok',
            'citus'  => 'connected',
            'redis'  => (string) $redisPing,
        ]);
    });

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
});
