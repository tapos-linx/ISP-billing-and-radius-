<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\Audit\BillingAuditService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Radius\RadiusCoaService;
use App\Services\Radius\RadiusSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * P0-7: Stripe Gateway Implementation (Global Cards: Visa, MasterCard, Amex)
 */
class StripeGateway implements PaymentGatewayInterface
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected RadiusSyncService $radiusSyncService,
        protected RadiusCoaService $coaService,
        protected BillingAuditService $auditService
    ) {}

    public function initiatePayment(Invoice $invoice, array $options = []): array
    {
        $secretKey = (string) config('services.stripe.secret', 'sk_test_tapos_stripe_key');
        $totalBdt = (float) ($invoice->total_bdt ?: $invoice->amount_bdt);
        $sessionId = 'cs_test_'.Str::random(24);

        if (config('services.stripe.mock', true)) {
            return [
                'status' => 'success',
                'redirect_url' => "https://checkout.stripe.com/c/pay/{$sessionId}",
                'session_id' => $sessionId,
                'gateway' => 'stripe',
            ];
        }

        try {
            // Amount in smallest currency unit (cent/paisa equivalent)
            $response = Http::withToken($secretKey)
                ->asForm()
                ->timeout(10)
                ->post('https://api.stripe.com/v1/checkout/sessions', [
                    'payment_method_types' => ['card'],
                    'line_items' => [[
                        'price_data' => [
                            'currency' => 'bdt',
                            'product_data' => [
                                'name' => 'Tapos Linx Broadband Internet Subscription',
                            ],
                            'unit_amount' => (int) round($totalBdt * 100),
                        ],
                        'quantity' => 1,
                    ]],
                    'mode' => 'payment',
                    'success_url' => url('/api/v1/payments/card/stripe/success?session_id={CHECKOUT_SESSION_ID}'),
                    'cancel_url' => url('/api/v1/payments/card/stripe/cancel'),
                    'client_reference_id' => (string) $invoice->invoice_id,
                    'metadata' => [
                        'invoice_id' => (string) $invoice->invoice_id,
                        'subscriber_id' => (string) $invoice->subscriber_id,
                    ],
                ]);

            $data = $response->json();
            if (isset($data['id'])) {
                return [
                    'status' => 'success',
                    'redirect_url' => (string) ($data['url'] ?? ''),
                    'session_id' => (string) $data['id'],
                    'gateway' => 'stripe',
                ];
            }

            throw new RuntimeException('Stripe checkout session failed: '.json_encode($data));
        } catch (Throwable $e) {
            Log::error('Stripe initiatePayment error: '.$e->getMessage());

            return [
                'status' => 'failed',
                'redirect_url' => '',
                'session_id' => $sessionId,
                'gateway' => 'stripe',
            ];
        }
    }

    public function verifyPayment(array $payload): array
    {
        $sessionId = (string) ($payload['session_id'] ?? $payload['payment_intent'] ?? '');
        $secretKey = (string) config('services.stripe.secret', 'sk_test_tapos_stripe_key');

        if ($sessionId === '') {
            return [
                'verified' => false,
                'gateway' => 'stripe',
                'trx_id' => '',
                'amount_bdt' => 0.0,
                'status' => 'MISSING_ID',
                'raw_response' => 'No session_id or payment_intent provided',
            ];
        }

        if (config('services.stripe.mock', true)) {
            return [
                'verified' => true,
                'gateway' => 'stripe',
                'trx_id' => 'pi_mock_'.time(),
                'amount_bdt' => (float) ($payload['amount'] ?? 1000.00),
                'status' => 'succeeded',
                'raw_response' => ['status' => 'succeeded', 'id' => $sessionId],
            ];
        }

        try {
            $response = Http::withToken($secretKey)
                ->timeout(10)
                ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");

            $data = $response->json();
            $paymentStatus = $data['payment_status'] ?? '';
            $verified = ($paymentStatus === 'paid');

            return [
                'verified' => $verified,
                'gateway' => 'stripe',
                'trx_id' => (string) ($data['payment_intent'] ?? $sessionId),
                'amount_bdt' => (float) (($data['amount_total'] ?? 0) / 100),
                'status' => $paymentStatus,
                'raw_response' => $data,
            ];
        } catch (Throwable $e) {
            Log::error('Stripe verifyPayment error: '.$e->getMessage());

            return [
                'verified' => false,
                'gateway' => 'stripe',
                'trx_id' => $sessionId,
                'amount_bdt' => 0.0,
                'status' => 'ERROR',
                'raw_response' => $e->getMessage(),
            ];
        }
    }

    public function handleWebhook(array $payload, array $headers = []): array
    {
        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? '');
        $dataObject = $payload['data']['object'] ?? [];

        if ($eventId === '') {
            throw new InvalidArgumentException('Missing Stripe event ID.');
        }

        // Signature validation if secret configured
        $webhookSecret = (string) config('services.stripe.webhook_secret', '');
        $sigHeader = $headers['stripe-signature'] ?? $headers['Stripe-Signature'] ?? null;
        if ($webhookSecret !== '' && $sigHeader !== null) {
            $this->verifySignature($payload, $sigHeader, $webhookSecret);
        }

        // Redis SETNX transaction idempotency guard
        $lockKey = "webhook:stripe:{$eventId}";
        $acquired = Redis::set($lockKey, '1', 'EX', 86400 * 7, 'NX');
        if (! $acquired) {
            Log::info("Stripe duplicate event {$eventId} ignored.");

            return [
                'status' => 'duplicate_ignored',
                'trx_id' => $eventId,
                'amount_bdt' => null,
                'invoice_id' => null,
                'message' => 'Event already processed',
            ];
        }

        if ($eventType !== 'checkout.session.completed' && $eventType !== 'payment_intent.succeeded') {
            return [
                'status' => 'ignored',
                'trx_id' => $eventId,
                'amount_bdt' => null,
                'invoice_id' => null,
                'message' => "Ignored event type: {$eventType}",
            ];
        }

        $trxId = (string) ($dataObject['payment_intent'] ?? $dataObject['id'] ?? $eventId);
        $amountBdt = (float) (($dataObject['amount_total'] ?? $dataObject['amount'] ?? 0) / 100);
        $invoiceId = (string) ($dataObject['metadata']['invoice_id'] ?? $dataObject['client_reference_id'] ?? '');
        $subscriberId = (string) ($dataObject['metadata']['subscriber_id'] ?? '');

        DB::beginTransaction();
        try {
            $invoice = Invoice::withoutGlobalScopes()->where('invoice_id', $invoiceId)->first();
            if ($invoice && $subscriberId === '') {
                $subscriberId = (string) $invoice->subscriber_id;
            }

            $payment = Payment::withoutGlobalScopes()->create([
                'payment_id' => (string) Str::uuid(),
                'tenant_id' => $invoice ? (int) $invoice->tenant_id : 1,
                'subscriber_id' => $subscriberId,
                'invoice_id' => $invoiceId,
                'gateway' => 'stripe',
                'trx_id' => $trxId,
                'amount_bdt' => $amountBdt,
                'hmac_verified' => true,
                'status' => 'completed',
                'paid_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);

            if ($invoice) {
                $invoice->status = 'paid';
                $invoice->save();

                // Double entry: DR Cash/Bank (1010), CR Accounts Receivable (1100)
                $this->ledgerService->recordPaymentSettlement($invoice, $payment);
            }

            // Extend subscriber expiration & activate
            $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $subscriberId)->first();
            if ($subscriber) {
                $baseTime = ($subscriber->expires_at && $subscriber->expires_at->isFuture())
                    ? $subscriber->expires_at->copy()
                    : Carbon::now();

                $subscriber->status = 'active';
                $subscriber->expires_at = $baseTime->addDays(30);
                $subscriber->save();

                // FreeRADIUS sync
                $password = (string) (DB::table('radcheck')
                    ->where('subscriber_id', $subscriberId)
                    ->where('attribute', 'Cleartext-Password')
                    ->value('value') ?: 'Tapos@2026');

                $this->radiusSyncService->syncSubscriber([
                    'subscriber_id' => $subscriberId,
                    'username' => $subscriber->username,
                    'password' => $password,
                    'rate_limit' => (string) ($subscriber->bandwidth_profile ?? '20M/20M'),
                    'status' => 'active',
                ]);
            }

            $this->auditService->logEvent(
                eventType: 'stripe_payment_settled',
                entityType: 'payment',
                entityId: (string) $payment->payment_id,
                actorType: 'gateway_webhook',
                actorId: 'stripe',
                prevState: ['invoice_status' => 'unpaid'],
                newState: ['trx_id' => $trxId, 'amount_bdt' => $amountBdt, 'invoice_id' => $invoiceId]
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Stripe settlement transaction error: '.$e->getMessage());
            throw $e;
        }

        // Fast-path unblock: CoA kick to live BNG (<3s SLA)
        if ($subscriber) {
            try {
                $this->coaService->disconnect($subscriber->username, $subscriberId);
            } catch (Throwable $e) {
                // Handled
            }
        }

        return [
            'status' => 'success',
            'trx_id' => $trxId,
            'amount_bdt' => $amountBdt,
            'invoice_id' => $invoiceId,
            'message' => 'Stripe payment settled successfully and subscriber line unblocked',
        ];
    }

    protected function verifySignature(array $payload, string $sigHeader, string $secret): void
    {
        $elements = explode(',', $sigHeader);
        $timestamp = '';
        $signatures = [];

        foreach ($elements as $element) {
            $parts = explode('=', trim($element), 2);
            if (count($parts) === 2) {
                if ($parts[0] === 't') {
                    $timestamp = $parts[1];
                } elseif ($parts[0] === 'v1') {
                    $signatures[] = $parts[1];
                }
            }
        }

        if ($timestamp === '' || empty($signatures)) {
            throw new RuntimeException('Invalid Stripe signature header format.');
        }

        $signedPayload = $timestamp.'.'.json_encode($payload);
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        $matched = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expectedSignature, $sig)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            throw new RuntimeException('Stripe webhook signature verification failed.');
        }
    }
}
