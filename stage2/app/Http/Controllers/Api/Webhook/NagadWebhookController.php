<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhook;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriberStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Subscriber;
use App\Services\RadiusCoaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * CP-03.M5: Nagad Merchant PGW Instant-Unblock Webhook Controller.
 *
 * Endpoint: POST /api/webhooks/nagad
 * SLA Target: < 3,000ms end-to-end (Webhook -> DB Transaction -> radreply sync -> UDP 3799 PoD).
 */
class NagadWebhookController extends Controller
{
    private const IDEMPOTENCY_TTL_SECONDS = 604800; // 7 days

    public function __construct(
        private readonly RadiusCoaService $radiusCoaService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Security: HMAC-SHA256 Signature Verification
        $providedSignature = (string) ($request->header('X-Nagad-Signature')
            ?? $request->header('X-Signature')
            ?? '');

        if (! $this->hasValidHmacSignature($request->getContent(), $providedSignature)) {
            Log::warning('Nagad Webhook rejected: Invalid HMAC-SHA256 signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid webhook HMAC signature.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Validate Nagad Payload Fields
        $trxId = trim((string) ($request->input('issuerPaymentRefNo') ?? $request->input('paymentRefId') ?? $request->input('transaction_id') ?? ''));
        $invoiceRef = trim((string) ($request->input('orderId') ?? $request->input('invoice_number') ?? $request->input('order_id') ?? ''));
        $paymentStatus = strtoupper(trim((string) ($request->input('status') ?? 'SUCCESS')));

        if ($trxId === '' || $invoiceRef === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Missing required issuerPaymentRefNo or orderId.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($paymentStatus !== 'SUCCESS') {
            return response()->json([
                'status'  => 'ignored',
                'message' => "Nagad payment status [{$paymentStatus}] does not trigger unblock.",
            ], Response::HTTP_OK);
        }

        // 3. Idempotency: Atomic Redis SETNX via Laravel Cache::add()
        $idempotencyKey = "mfs_webhook:nagad:trx:{$trxId}";
        $acquiredLock = Cache::add(
            $idempotencyKey,
            Carbon::now()->toIso8601String(),
            self::IDEMPOTENCY_TTL_SECONDS
        );

        if (! $acquiredLock) {
            return response()->json([
                'status'  => 'duplicate_ignored',
                'trx_id'  => $trxId,
                'message' => 'Transaction already processed.',
            ], Response::HTTP_OK);
        }

        try {
            // 4. Atomic Database Transaction: Mark Invoice PAID + Extend Subscriber +30 Days & Activate
            /** @var array{invoice: Invoice, subscriber: Subscriber}|null $processed */
            $processed = DB::transaction(function () use ($invoiceRef, $trxId, $request): ?array {
                /** @var Invoice|null $invoice */
                $invoice = Invoice::query()
                    ->where('invoice_number', $invoiceRef)
                    ->orWhere('id', is_numeric($invoiceRef) ? (int) $invoiceRef : 0)
                    ->lockForUpdate()
                    ->first();

                if ($invoice === null) {
                    return null;
                }

                // Mark Invoice as PAID
                $invoice->update([
                    'status'          => InvoiceStatus::Paid,
                    'payment_gateway' => 'nagad',
                    'gateway_trx_id'  => $trxId,
                    'paid_at'         => Carbon::now(),
                    'webhook_payload' => $request->all(),
                ]);

                /** @var Subscriber|null $subscriber */
                $subscriber = Subscriber::query()
                    ->whereKey($invoice->subscriber_id)
                    ->lockForUpdate()
                    ->first();

                if ($subscriber === null) {
                    return null;
                }

                // Calculate new expiry (+30 days from current future expiry, or +30 days from now if expired)
                $currentExpiry = $subscriber->expires_at;
                $baseTimestamp = ($currentExpiry !== null && $currentExpiry->isFuture())
                    ? $currentExpiry->copy()
                    : Carbon::now();
                $newExpiry = $baseTimestamp->addDays(30);

                $updatePayload = [
                    'status'     => SubscriberStatus::Active,
                    'expires_at' => $newExpiry,
                ];

                if (Schema::hasColumn('subscribers', 'expiry_date')) {
                    $updatePayload['expiry_date'] = $newExpiry;
                }

                // Updating Subscriber automatically fires SubscriberObserver::updated() ->
                // RadiusSyncService::syncSubscriber(), restoring Framed-Pool := pppoe_active_pool
                // and full Mikrotik-Rate-Limit inside this same transaction!
                $subscriber->update($updatePayload);

                return [
                    'invoice'    => $invoice,
                    'subscriber' => $subscriber->fresh(['nas', 'tariff']) ?? $subscriber,
                ];
            });

            if ($processed === null) {
                Cache::forget($idempotencyKey);

                return response()->json([
                    'status'  => 'error',
                    'message' => "Invoice [{$invoiceRef}] or linked Subscriber not found.",
                ], Response::HTTP_NOT_FOUND);
            }

            // 5. Trigger RadiusCoaService on UDP 3799 to kick Subscriber from Walled Garden
            $coaResult = $this->radiusCoaService->sendPacketOfDisconnect($processed['subscriber']);

            return response()->json([
                'status'         => 'ok',
                'gateway'        => 'nagad',
                'trx_id'         => $trxId,
                'invoice_number' => $processed['invoice']->invoice_number,
                'invoice_status' => 'PAID',
                'subscriber'     => $processed['subscriber']->username,
                'new_status'     => $processed['subscriber']->status->value,
                'new_expiry'     => $processed['subscriber']->expires_at?->toIso8601String(),
                'coa_dispatched' => $coaResult['success'],
                'coa_port'       => $coaResult['port'],
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            Cache::forget($idempotencyKey);

            Log::error('Nagad Webhook processing failed', [
                'trx_id'      => $trxId,
                'invoice_ref' => $invoiceRef,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Internal webhook processing error.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Constant-time HMAC-SHA256 verification against the configured Nagad webhook secret.
     */
    private function hasValidHmacSignature(string $rawPayload, string $providedSignature): bool
    {
        if ($providedSignature === '') {
            return false;
        }

        $secret = (string) config('services.nagad.webhook_secret', env('NAGAD_WEBHOOK_SECRET', 'nagad_carrier_hmac_secret_2026'));

        $expectedSignature = hash_hmac('sha256', $rawPayload, $secret);

        return hash_equals($expectedSignature, $providedSignature);
    }
}
