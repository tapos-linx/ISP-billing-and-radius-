<?php

declare(strict_types=1);

namespace App\Services\Hotspot;

use App\Models\HotspotVoucher;
use Carbon\Carbon;

/**
 * P1-17: Dynamic Captive Portal Builder & WISPr Protocol Engine
 * Generates custom-branded captive portal HTML and WISPr XML redirections
 * for public Wi-Fi hotspot vouchers and walled-garden authentication.
 */
class CaptivePortalBuilderService
{
    /**
     * Generate responsive HTML5 captive login portal with brand customization
     *
     * @param  array<string, string>  $themeOptions
     */
    public function renderPortalHtml(string $brandName, array $themeOptions = []): string
    {
        $primaryColor = $themeOptions['primary_color'] ?? '#2563eb';
        $accentColor = $themeOptions['accent_color'] ?? '#1d4ed8';
        $logoUrl = $themeOptions['logo_url'] ?? '';

        $logoHtml = $logoUrl !== ''
            ? "<img src=\"{$logoUrl}\" alt=\"{$brandName}\" class=\"h-12 mx-auto mb-4\" />"
            : "<h1 class=\"text-2xl font-bold text-center mb-4\" style=\"color: {$primaryColor};\">{$brandName} Wi-Fi</h1>";

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$brandName} - Public Wi-Fi Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .btn-brand { background-color: {$primaryColor}; }
        .btn-brand:hover { background-color: {$accentColor}; }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <div class="bg-white shadow-xl rounded-2xl max-w-md w-full p-6">
        {$logoHtml}
        <p class="text-sm text-gray-600 text-center mb-6">Enter your prepaid voucher code or PIN to connect to high-speed internet.</p>
        <form method="POST" action="/api/v1/hotspot/login" class="space-y-4">
            <div>
                <label for="voucher_code" class="block text-xs font-semibold uppercase text-gray-700 mb-1">Voucher Code</label>
                <input type="text" id="voucher_code" name="voucher_code" placeholder="e.g. VCH-98214" required
                       class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 font-mono text-center tracking-widest text-lg" />
            </div>
            <div>
                <label for="voucher_pin" class="block text-xs font-semibold uppercase text-gray-700 mb-1">PIN (Optional)</label>
                <input type="password" id="voucher_pin" name="voucher_pin" placeholder="••••"
                       class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 font-mono text-center text-sm" />
            </div>
            <button type="submit" class="w-full py-3 text-white font-semibold rounded-lg shadow-md btn-brand transition-colors">
                Connect to Internet
            </button>
        </form>
        <div class="mt-6 pt-4 border-t border-gray-200 text-center">
            <span class="text-xs text-gray-400">Powered by {$brandName} Carrier Core</span>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Generate standard RFC / WISPr 1.0 XML response payload for iOS/Android CNA detection
     */
    public function generateWisprResponse(string $loginUrl, int $responseCode = 50, string $message = 'Login required'): string
    {
        return <<<XML
<WISPAccessGatewayParam xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="http://www.wballiance.net/wispr_v1.0.xsd">
    <Redirect>
        <AccessProcedure>1.0</AccessProcedure>
        <AccessLocation>Carrier-Public-Hotspot</AccessLocation>
        <LocationName>Carrier Wi-Fi Hotspot</LocationName>
        <LoginURL>{$loginUrl}</LoginURL>
        <MessageType>{$responseCode}</MessageType>
        <ResponseCode>{$responseCode}</ResponseCode>
        <Message>{$message}</Message>
    </Redirect>
</WISPAccessGatewayParam>
XML;
    }

    /**
     * Authenticate and authorize a hotspot voucher from portal login
     *
     * @return array<string, mixed>
     */
    public function authenticateVoucher(string $voucherCode, ?string $pin = null, ?string $macAddress = null): array
    {
        /** @var HotspotVoucher|null $voucher */
        $voucher = HotspotVoucher::withoutGlobalScopes()
            ->where('code', strtoupper(trim($voucherCode)))
            ->first();

        if ($voucher === null) {
            return [
                'authorized' => false,
                'status' => 'invalid_code',
                'message' => 'Voucher code not found.',
            ];
        }

        if ($pin !== null && $voucher->pin !== null && $voucher->pin !== $pin) {
            return [
                'authorized' => false,
                'status' => 'invalid_pin',
                'message' => 'Incorrect voucher PIN.',
            ];
        }

        if ($voucher->status === 'expired' || ($voucher->expires_at && $voucher->expires_at < Carbon::now())) {
            return [
                'authorized' => false,
                'status' => 'expired',
                'message' => 'Voucher has expired.',
            ];
        }

        if ($voucher->status === 'active' && $voucher->mac_address && $macAddress && $voucher->mac_address !== $macAddress) {
            return [
                'authorized' => false,
                'status' => 'device_mismatch',
                'message' => 'Voucher is locked to another device MAC address.',
            ];
        }

        // Activate voucher on first login
        if ($voucher->status === 'created') {
            $voucher->status = 'active';
            $voucher->first_login_at = Carbon::now();
            if ($voucher->time_limit_seconds > 0) {
                $voucher->expires_at = Carbon::now()->addSeconds($voucher->time_limit_seconds);
            }
        }

        if ($macAddress) {
            $voucher->mac_address = $macAddress;
        }

        $voucher->save();

        return [
            'authorized' => true,
            'status' => 'active',
            'voucher_id' => $voucher->voucher_id,
            'code' => $voucher->code,
            'time_limit_seconds' => $voucher->time_limit_seconds,
            'rate_limit_down_kbps' => $voucher->rate_limit_down_kbps,
            'rate_limit_up_kbps' => $voucher->rate_limit_up_kbps,
            'wispr_bandwidth_max_down' => $voucher->rate_limit_down_kbps * 1024,
            'wispr_bandwidth_max_up' => $voucher->rate_limit_up_kbps * 1024,
            'expires_at' => $voucher->expires_at?->toIso8601String(),
        ];
    }
}
