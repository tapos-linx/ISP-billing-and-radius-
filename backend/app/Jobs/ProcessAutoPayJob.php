<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentToken;
use App\Models\Subscriber;
use App\Services\Audit\BillingAuditService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Radius\RadiusCoaService;
use App\Services\Radius\RadiusSyncService;
use App\Services\Sms\SmsService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * P0-8: Scheduled Auto-Pay Processor with Day 1 / 3 / 7 Retry Logic
 */
class ProcessAutoPayJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_RETRIES = 3;

    public function handle(
        DoubleEntryLedgerService $ledgerService,
        RadiusSyncService $radiusSync,
        RadiusCoaService $coaService,
        BillingAuditService $auditService,
        SmsService $smsService
    ): array {
        $now = Carbon::now();
        $processed = 0;
        $succeeded = 0;
        $failed = 0;

        // Find subscribers with auto_pay_enabled having unpaid invoices
        $subscribers = Subscriber::withoutGlobalScopes()
            ->where('auto_pay_enabled', true)
            ->get();

        foreach ($subscribers as $subscriber) {
            $unpaidInvoice = Invoice::withoutGlobalScopes()
                ->where('subscriber_id', $subscriber->subscriber_id)
                ->where('status', 'unpaid')
                ->where('due_date', '<=', $now)
                ->first();

            if (! $unpaidInvoice) {
                continue;
            }

            $token = PaymentToken::withoutGlobalScopes()
                ->where('subscriber_id', $subscriber->subscriber_id)
                ->where('status', 'active')
                ->where('is_default', true)
                ->first();

            if (! $token) {
                continue;
            }

            // Check retry intervals: Attempt 1 (Day 1), Attempt 2 (Day 3), Attempt 3 (Day 7)
            if ($token->retry_count >= self::MAX_RETRIES) {
                continue;
            }

            if ($token->last_retry_at !== null) {
                $daysSinceLast = $token->last_retry_at->diffInDays($now);
                if ($token->retry_count === 1 && $daysSinceLast < 2) {
                    continue; // Wait until Day 3
                }
                if ($token->retry_count === 2 && $daysSinceLast < 4) {
                    continue; // Wait until Day 7
                }
            }

            $processed++;

            // Execute tokenized charge attempt
            $chargeSuccess = $this->executeTokenCharge($token, $unpaidInvoice);

            if ($chargeSuccess) {
                DB::beginTransaction();
                try {
                    $amount = (float) ($unpaidInvoice->total_bdt ?: $unpaidInvoice->amount_bdt);
                    $trxId = 'AUTOPAY-'.strtoupper(Str::random(10));

                    $payment = Payment::withoutGlobalScopes()->create([
                        'payment_id' => (string) Str::uuid(),
                        'tenant_id' => (int) $subscriber->tenant_id,
                        'subscriber_id' => (string) $subscriber->subscriber_id,
                        'invoice_id' => (string) $unpaidInvoice->invoice_id,
                        'gateway' => $token->gateway,
                        'trx_id' => $trxId,
                        'amount_bdt' => $amount,
                        'hmac_verified' => true,
                        'status' => 'completed',
                        'paid_at' => $now,
                        'created_at' => $now,
                    ]);

                    $unpaidInvoice->status = 'paid';
                    $unpaidInvoice->save();

                    // Double-entry ledger
                    $ledgerService->recordPaymentSettlement($unpaidInvoice, $payment);

                    // Extend subscriber
                    $base = ($subscriber->expires_at && $subscriber->expires_at->isFuture())
                        ? $subscriber->expires_at->copy()
                        : $now->copy();

                    $subscriber->status = 'active';
                    $subscriber->expires_at = $base->addDays(30);
                    $subscriber->save();

                    // Reset token retry counter
                    $token->retry_count = 0;
                    $token->last_retry_at = $now;
                    $token->save();

                    // FreeRADIUS sync
                    $password = (string) (DB::table('radcheck')
                        ->where('subscriber_id', (string) $subscriber->subscriber_id)
                        ->where('attribute', 'Cleartext-Password')
                        ->value('value') ?: 'Tapos@2026');

                    $radiusSync->syncSubscriber([
                        'subscriber_id' => (string) $subscriber->subscriber_id,
                        'username' => (string) $subscriber->username,
                        'password' => $password,
                        'rate_limit' => (string) ($subscriber->bandwidth_profile ?? '20M/20M'),
                        'status' => 'active',
                    ]);

                    $auditService->logEvent(
                        eventType: 'autopay_succeeded',
                        entityType: 'invoice',
                        entityId: (string) $unpaidInvoice->invoice_id,
                        actorType: 'scheduler',
                        actorId: 'autopay_worker',
                        prevState: ['status' => 'unpaid'],
                        newState: ['status' => 'paid', 'trx_id' => $trxId]
                    );

                    DB::commit();
                    $succeeded++;

                    // Fast-path unblock
                    try {
                        $coaService->disconnect((string) $subscriber->username, (string) $subscriber->subscriber_id);
                    } catch (Throwable $e) {
                        // Handled
                    }

                    // Send SMS confirmation
                    $smsService->sendPaymentConfirmation(
                        '01700000000',
                        $amount,
                        $trxId
                    );
                } catch (Throwable $e) {
                    DB::rollBack();
                    Log::error('AutoPay settlement failure: '.$e->getMessage());
                    $failed++;
                }
            } else {
                // Charge failed: increment retry count
                $token->retry_count++;
                $token->last_retry_at = $now;
                $token->save();
                $failed++;

                Log::warning(sprintf(
                    'AutoPay token charge failed for subscriber %s, invoice %s. Retry count: %d',
                    $subscriber->subscriber_id,
                    $unpaidInvoice->invoice_id,
                    $token->retry_count
                ));

                // Send SMS alert on retry failure
                $smsService->send(
                    '01700000000',
                    sprintf('Auto-pay attempt %d/3 failed for invoice #%s. Please pay manually to avoid disconnection.', $token->retry_count, substr((string) $unpaidInvoice->invoice_id, 0, 8)),
                    'AUTOPAY_FAILED'
                );
            }
        }

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }

    /**
     * Simulated tokenized charge call to payment gateway (Stripe or SSLCommerz token API)
     */
    protected function executeTokenCharge(PaymentToken $token, Invoice $invoice): bool
    {
        // For testing/mocking, tokens beginning with 'tok_fail' will fail, all other active tokens succeed
        if (str_starts_with($token->payment_method_token, 'tok_fail')) {
            return false;
        }

        return true;
    }
}
