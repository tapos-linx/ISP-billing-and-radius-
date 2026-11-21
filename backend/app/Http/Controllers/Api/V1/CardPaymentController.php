<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Payment\SslCommerzGateway;
use App\Services\Payment\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * P0-7: Universal Card Payment Gateway API Controller
 */
class CardPaymentController extends Controller
{
    public function __construct(
        protected SslCommerzGateway $sslCommerz,
        protected StripeGateway $stripe
    ) {}

    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => 'required|uuid',
            'gateway' => 'required|string|in:sslcommerz,stripe',
        ]);

        $invoice = Invoice::withoutGlobalScopes()->where('invoice_id', $validated['invoice_id'])->firstOrFail();

        $gateway = match ($validated['gateway']) {
            'sslcommerz' => $this->sslCommerz,
            'stripe' => $this->stripe,
            default => throw new InvalidArgumentException('Unsupported gateway: '.$validated['gateway']),
        };

        $result = $gateway->initiatePayment($invoice);

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }

    public function handleSslCommerzIpn(Request $request): JsonResponse
    {
        $payload = $request->all();
        $headers = array_map(fn ($h) => is_array($h) ? (string) reset($h) : (string) $h, $request->headers->all());

        $result = $this->sslCommerz->handleWebhook($payload, $headers);

        $code = ($result['status'] === 'duplicate_ignored') ? 409 : 200;

        return response()->json($result, $code);
    }

    public function handleStripeWebhook(Request $request): JsonResponse
    {
        $payload = $request->all();
        $headers = array_map(fn ($h) => is_array($h) ? (string) reset($h) : (string) $h, $request->headers->all());

        $result = $this->stripe->handleWebhook($payload, $headers);

        $code = ($result['status'] === 'duplicate_ignored') ? 409 : 200;

        return response()->json($result, $code);
    }
}
