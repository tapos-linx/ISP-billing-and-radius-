<?php

use App\Http\Controllers\SelfCareController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SelfCareController::class, 'index'])->name('home');
Route::get('/self-care', [SelfCareController::class, 'index'])->name('self-care.index');
Route::post('/self-care/pay/{gateway}', [SelfCareController::class, 'initiatePayment'])->name('self-care.pay');
Route::post('/two-factor-challenge', [SelfCareController::class, 'twoFactorChallenge'])->name('two-factor.challenge');
Route::post('/self-care/2fa/toggle', [SelfCareController::class, 'toggleTwoFactor'])->name('self-care.2fa.toggle');
