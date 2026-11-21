<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HotspotProfile;
use App\Models\HotspotVoucher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Release 2.1: Wi-Fi Hotspot Captive Portal & Thermal Print Web Controller
 */
class HotspotWebController extends Controller
{
    /**
     * Display the Public Wi-Fi Hotspot Captive Portal
     */
    public function login(Request $request): Response
    {
        $profiles = HotspotProfile::withoutGlobalScopes()
            ->orderBy('price_bdt', 'asc')
            ->get();

        return Inertia::render('Hotspot/CaptivePortal', [
            'profiles' => $profiles,
            'clientIp' => $request->ip() ?? '10.10.10.50',
            'clientMac' => $request->query('mac', '00:11:22:33:44:55'),
            'linkLogin' => $request->query('link-login', 'http://hotspot.carrier.net/login'),
            'linkOrig' => $request->query('link-orig', 'https://www.google.com'),
            'error' => $request->query('error'),
            'hotspotName' => 'Carrier Gigabit Public Wi-Fi',
        ]);
    }

    /**
     * Render the 80mm ESC/POS Thermal Print Preview for a Voucher Batch
     */
    public function printBatch(string $batchId): Response
    {
        $vouchers = HotspotVoucher::withoutGlobalScopes()
            ->where('batch_id', $batchId)
            ->with('profile')
            ->orderBy('created_at', 'asc')
            ->get();

        return Inertia::render('Hotspot/VoucherPrint', [
            'batchId' => $batchId,
            'vouchers' => $vouchers,
            'count' => $vouchers->count(),
            'hotspotName' => 'Carrier Gigabit Wi-Fi',
            'supportHelpline' => '16222',
            'vatRatePercent' => 5.0,
        ]);
    }

    /**
     * Render single voucher for POS thermal receipt printing
     */
    public function printVoucher(string $code): Response
    {
        $voucher = HotspotVoucher::withoutGlobalScopes()
            ->where('code', $code)
            ->with('profile')
            ->firstOrFail();

        return Inertia::render('Hotspot/VoucherPrint', [
            'batchId' => $voucher->batch_id,
            'vouchers' => [$voucher],
            'count' => 1,
            'hotspotName' => 'Carrier Gigabit Wi-Fi',
            'supportHelpline' => '16222',
            'vatRatePercent' => 5.0,
        ]);
    }
}
