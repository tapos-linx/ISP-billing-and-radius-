<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Webhook\BkashWebhookController;
use App\Http\Controllers\Api\Webhook\NagadWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CP-03.M5: Carrier MFS Instant-Unblock Webhook Routes
|--------------------------------------------------------------------------
| Mounted under `/api` prefix by Laravel 11 `bootstrap/app.php`:
|   POST /api/webhooks/bkash -> BkashWebhookController
|   POST /api/webhooks/nagad -> NagadWebhookController
*/

Route::prefix('webhooks')
    ->name('api.webhooks.')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->group(function (): void {
        Route::post('/bkash', BkashWebhookController::class)->name('bkash');
        Route::post('/nagad', NagadWebhookController::class)->name('nagad');
    });
