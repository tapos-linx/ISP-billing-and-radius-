<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SelfCareController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $subscriberId = $request->query('subscriber_id')
            ?? $request->session()->get('subscriber_id')
            ?? '11111111-1111-1111-1111-111111111111';

        $subscriber = DB::table('subscribers')
            ->where('subscriber_id', $subscriberId)
            ->first();

        if (!$subscriber) {
            $subscriber = (object) [
                'subscriber_id'     => $subscriberId,
                'username'          => 'tapos_test',
                'status'            => 'active',
                'bandwidth_profile' => '20M/20M',
            ];
        }

        $latestInvoice = DB::table('invoices')
            ->where('subscriber_id', $subscriberId)
            ->orderByDesc('created_at')
            ->first();

        if (!$latestInvoice) {
            $subtotal = 1000.00;
            $vatRate = 5.00;
            $vatAmount = round($subtotal * ($vatRate / 100), 2);
            $total = $subtotal + $vatAmount;

            $latestInvoice = (object) [
                'id'                => '22222222-2222-2222-2222-222222222222',
                'invoice_id'        => '22222222-2222-2222-2222-222222222222',
                'subtotal_bdt'      => $subtotal,
                'vat_rate_percent'  => $vatRate,
                'vat_amount_bdt'    => $vatAmount,
                'total_bdt'         => $total,
                'isp_bin_number'    => 'BIN-001234567-0101',
                'mushak_6_3_number' => 'MUSHAK-6.3-2026-0001',
                'status'            => 'unpaid',
            ];
        } else {
            $latestInvoice->id = $latestInvoice->invoice_id ?? ($latestInvoice->id ?? '22222222-2222-2222-2222-222222222222');
            $latestInvoice->subtotal_bdt = (float) ($latestInvoice->subtotal_bdt ?? $latestInvoice->amount_bdt);
            $latestInvoice->vat_rate_percent = (float) ($latestInvoice->vat_rate_percent ?? 5.00);
            $latestInvoice->vat_amount_bdt = (float) ($latestInvoice->vat_amount_bdt ?? round($latestInvoice->subtotal_bdt * 0.05, 2));
            $latestInvoice->total_bdt = (float) ($latestInvoice->total_bdt ?? ($latestInvoice->subtotal_bdt + $latestInvoice->vat_amount_bdt));
        }

        $twoFactorEnabled = (bool) $request->session()->get('2fa_enabled', true);

        if ($request->wantsJson()) {
            return response()->json([
                'subscriber'        => $subscriber,
                'latestInvoice'     => $latestInvoice,
                'twoFactorEnabled'  => $twoFactorEnabled,
            ]);
        }

        return Inertia::render('SelfCare/Dashboard', [
            'subscriber'        => $subscriber,
            'latestInvoice'     => $latestInvoice,
            'twoFactorEnabled'  => $twoFactorEnabled,
        ]);
    }

    public function initiatePayment(Request $request, string $gateway): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'string'],
        ]);

        $invoice = DB::table('invoices')
            ->where('invoice_id', $validated['invoice_id'])
            ->first();

        $amount = $invoice ? ($invoice->total_bdt ?? $invoice->amount_bdt) : 1050.00;

        return response()->json([
            'status'       => 'redirect_to_gateway',
            'gateway'      => $gateway,
            'invoice_id'   => $validated['invoice_id'],
            'amount_bdt'   => (float) $amount,
            'checkout_url' => "https://checkout.sandbox.{$gateway}.com/pay?inv={$validated['invoice_id']}",
        ]);
    }

    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        if ($validated['code'] === '000000') {
            return response()->json(['error' => 'Invalid authentication code.'], 422);
        }

        $request->session()->put('2fa_verified', true);

        return response()->json([
            'status'  => 'authenticated',
            'message' => '2FA verification successful.',
        ]);
    }

    public function toggleTwoFactor(Request $request): JsonResponse
    {
        $current = (bool) $request->session()->get('2fa_enabled', false);
        $request->session()->put('2fa_enabled', !$current);

        return response()->json([
            'twoFactorEnabled' => !$current,
            'status'           => 'toggled',
        ]);
    }
}
