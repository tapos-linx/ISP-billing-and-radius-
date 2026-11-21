<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Invoice;

/**
 * P0-7: Universal Payment Gateway Interface for Card & MFS Providers
 */
interface PaymentGatewayInterface
{
    /**
     * Initiate a payment session and return redirect URL or gateway transaction payload.
     *
     * @param  array<string, mixed>  $options
     * @return array{
     *   status: string,
     *   redirect_url: string,
     *   session_id: string,
     *   gateway: string
     * }
     */
    public function initiatePayment(Invoice $invoice, array $options = []): array;

    /**
     * Verify payment status directly with the gateway API.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *   verified: bool,
     *   gateway: string,
     *   trx_id: string,
     *   amount_bdt: float,
     *   status: string,
     *   raw_response: mixed
     * }
     */
    public function verifyPayment(array $payload): array;

    /**
     * Handle incoming asynchronous webhook / IPN notification from gateway.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array{
     *   status: string,
     *   trx_id: ?string,
     *   amount_bdt: ?float,
     *   invoice_id: ?string,
     *   message: string
     * }
     */
    public function handleWebhook(array $payload, array $headers = []): array;
}
