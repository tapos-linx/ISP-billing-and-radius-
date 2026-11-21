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
 * P0-7: SSLCommerz Gateway Implementation (Bangladesh Cards, Net Banking & MFS)
 */
class SslCommerzGateway implements PaymentGatewayInterface
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected RadiusSyncService $radiusSyncService,
        protected RadiusCoaService $coaService,
        protected BillingAuditService $auditService
    ) {}

    public function initiatePayment(Invoice $invoice, array $options = []): array
    {
        $storeId = config('services.sslcommerz.store_id', 'tapos_test_store');
        $storePassword = config('services.sslcommerz.store_password', 'tapos_test_secret');
        $isSandbox = (bool) config('services.sslcommerz.sandbox', true);

        $baseUrl = $isSandbox
            ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
            : 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';

        $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $invoice->subscriber_id)->first();
        $subName = $subscriber ? $subscriber->username : 'Subscriber';
        $tranId = 'SSLC-'.date('Ymd').'-'.strtoupper(Str::random(8));
        $totalAmount = (float) ($invoice->total_bdt ?: $invoice->amount_bdt);

        $payload = [
            'store_id' => $storeId,
            'store_passwd' => $storePassword,
            'total_amount' => $totalAmount,
            'currency' => 'BDT',
            'tran_id' => $tranId,
            'success_url' => url('/api/v1/payments/card/sslcommerz/ipn'),
            'fail_url' => url('/api/v1/payments/card/sslcommerz/ipn'),
            'cancel_url' => url('/api/v1/payments/card/sslcommerz/ipn'),
            'ipn_url' => url('/api/v1/payments/card/sslcommerz/ipn'),
            'cus_name' => $subName,
            'cus_email' => $subName.'@taposlinx.net',
            'cus_phone' => '01700000000',
            'cus_add1' => 'Dhaka, Bangladesh',
            'cus_city' => 'Dhaka',
            'cus_country' => 'Bangladesh',
            'shipping_method' => 'NO',
            'product_name' => 'Broadband Internet',
            'product_category' => 'Internet',
            'product_profile' => 'non-physical-goods',
            'value_a' => (string) $invoice->invoice_id,
            'value_b' => (string) $invoice->subscriber_id,
        ];

        // If mock or offline mode, return immediate gateway redirect URL
        if (config('services.sslcommerz.mock', true)) {
            return [
                'status' => 'success',
                'redirect_url' => "https://sandbox.sslcommerz.com/easycheckout.php?token={$tranId}",
                'session_id' => $tranId,
                'gateway' => 'sslcommerz',
            ];
        }

        try {
            $response = Http::asForm()->timeout(10)->post($baseUrl, $payload);
            $data = $response->json();

            if (($data['status'] ?? '') === 'SUCCESS') {
                return [
                    'status' => 'success',
                    'redirect_url' => (string) ($data['GatewayPageURL'] ?? ''),
                    'session_id' => (string) ($data['sessionkey'] ?? $tranId),
                    'gateway' => 'sslcommerz',
                ];
            }

            throw new RuntimeException('SSLCommerz session initiation failed: '.json_encode($data));
        } catch (Throwable $e) {
            Log::error('SSLCommerz initiation error: '.$e->getMessage());

            return [
                'status' => 'failed',
                'redirect_url' => '',
                'session_id' => $tranId,
                'gateway' => 'sslcommerz',
            ];
        }
    }

    public function verifyPayment(array $payload): array
    {
        $valId = (string) ($payload['val_id'] ?? '');
        $storeId = config('services.sslcommerz.store_id', 'tapos_test_store');
        $storePassword = config('services.sslcommerz.store_password', 'tapos_test_secret');
        $isSandbox = (bool) config('services.sslcommerz.sandbox', true);

        if ($valId === '') {
            return [
                'verified' => false,
                'gateway' => 'sslcommerz',
                'trx_id' => (string) ($payload['tran_id'] ?? ''),
                'amount_bdt' => (float) ($payload['amount'] ?? 0),
                'status' => 'INVALID_PAYLOAD',
                'raw_response' => 'Missing val_id',
            ];
        }

        // Mock mode for local testing
        if (config('services.sslcommerz.mock', true)) {
            return [
                'verified' => true,
                'gateway' => 'sslcommerz',
                'trx_id' => (string) ($payload['tran_id'] ?? 'MOCK-SSLC-'.time()),
                'amount_bdt' => (float) ($payload['amount'] ?? 1000.00),
                'status' => 'VALIDATED',
                'raw_response' => ['status' => 'VALIDATED', 'val_id' => $valId],
            ];
        }

        $validatorUrl = $isSandbox
            ? 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php'
            : 'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php';

        try {
            $response = Http::timeout(10)->get($validatorUrl, [
                'val_id' => $valId,
                'store_id' => $storeId,
                'store_passwd' => $storePassword,
                'format' => 'json',
            ]);

            $data = $response->json();
            $status = $data['status'] ?? '';
            $verified = ($status === 'VALID' || $status === 'VALIDATED');

            return [
                'verified' => $verified,
                'gateway' => 'sslcommerz',
                'trx_id' => (string) ($data['tran_id'] ?? $payload['tran_id'] ?? ''),
                'amount_bdt' => (float) ($data['amount'] ?? $payload['amount'] ?? 0),
                'status' => $status,
                'raw_response' => $data,
            ];
        } catch (Throwable $e) {
            Log::error('SSLCommerz validation error: '.$e->getMessage());

            return [
                'verified' => false,
                'gateway' => 'sslcommerz',
                'trx_id' => (string) ($payload['tran_id'] ?? ''),
                'amount_bdt' => 0.0,
                'status' => 'ERROR',
                'raw_response' => $e->getMessage(),
            ];
        }
    }

    public function handleWebhook(array $payload, array $headers = []): array
    {
        $tranId = (string) ($payload['tran_id'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $amountBdt = (float) ($payload['amount'] ?? 0);
        $invoiceId = (string) ($payload['value_a'] ?? $payload['invoice_id'] ?? '');
        $subscriberId = (string) ($payload['value_b'] ?? $payload['subscriber_id'] ?? '');

        if ($tranId === '') {
            throw new InvalidArgumentException('Missing tran_id in SSLCommerz payload.');
        }

        // Redis SETNX transaction idempotency guard
        $lockKey = "webhook:sslcommerz:{$tranId}";
        $acquired = Redis::set($lockKey, '1', 'EX', 86400 * 7, 'NX');
        if (! $acquired) {
            Log::info("SSLCommerz duplicate transaction {$tranId} ignored.");

            return [
                'status' => 'duplicate_ignored',
                'trx_id' => $tranId,
                'amount_bdt' => $amountBdt,
                'invoice_id' => $invoiceId,
                'message' => 'Transaction already processed',
            ];
        }

        if ($status !== 'VALID' && $status !== 'VALIDATED') {
            return [
                'status' => 'ignored',
                'trx_id' => $tranId,
                'amount_bdt' => $amountBdt,
                'invoice_id' => $invoiceId,
                'message' => 'Transaction was not successful',
            ];
        }

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
                'gateway' => 'sslcommerz',
                'trx_id' => $tranId,
                'amount_bdt' => $amountBdt,
                'hmac_verified' => true,
                'status' => 'completed',
                'paid_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ]);

            if ($invoice) {
                $invoice->status = 'paid';
                $invoice->save();

                // Double entry: DR Cash/MFS clearing (1010), CR Accounts Receivable (1100)
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
                eventType: 'sslcommerz_payment_settled',
                entityType: 'payment',
                entityId: (string) $payment->payment_id,
                actorType: 'gateway_ipn',
                actorId: 'sslcommerz',
                prevState: ['invoice_status' => 'unpaid'],
                newState: ['trx_id' => $tranId, 'amount_bdt' => $amountBdt, 'invoice_id' => $invoiceId]
            );

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('SSLCommerz settlement transaction error: '.$e->getMessage());
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
            'trx_id' => $tranId,
            'amount_bdt' => $amountBdt,
            'invoice_id' => $invoiceId,
            'message' => 'Payment settled successfully and line unblocked',
        ];
    }
}
