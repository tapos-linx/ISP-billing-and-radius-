<?php

use App\Http\Controllers\HotspotWebController;
use App\Http\Controllers\NocConsoleController;
use App\Http\Controllers\SelfCareController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SelfCareController::class, 'index'])->name('home');
Route::get('/self-care', [SelfCareController::class, 'index'])->name('self-care.index');
Route::post('/self-care/pay/{gateway}', [SelfCareController::class, 'initiatePayment'])->name('self-care.pay');
Route::post('/two-factor-challenge', [SelfCareController::class, 'twoFactorChallenge'])->name('two-factor.challenge');
Route::post('/self-care/2fa/toggle', [SelfCareController::class, 'toggleTwoFactor'])->name('self-care.2fa.toggle');

// P0-1: NOC Command Console
Route::get('/noc/dashboard', [NocConsoleController::class, 'dashboard'])->name('noc.dashboard');
Route::get('/noc/carrier', [NocConsoleController::class, 'carrierOperations'])->name('noc.carrier');
Route::get('/noc/resources/{resource}', [NocConsoleController::class, 'resources'])->name('noc.resources');

// Release 2.1: Wi-Fi Hotspot Captive Portal & Reseller POS Printing
Route::get('/hotspot/login', [HotspotWebController::class, 'login'])->name('hotspot.login');
Route::get('/hotspot/print/{batchId}', [HotspotWebController::class, 'printBatch'])->name('hotspot.print');
Route::get('/hotspot/print-voucher/{code}', [HotspotWebController::class, 'printVoucher'])->name('hotspot.print.voucher');
