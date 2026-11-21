<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessAutoPayJob;
use App\Jobs\SendSmsNotificationJob;
use App\Mail\InvoiceGeneratedMail;
use App\Models\Invoice;
use App\Models\PaymentToken;
use App\Models\Subscriber;
use App\Services\Audit\BillingAuditService;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Pdf\PdfInvoiceService;
use App\Services\Radius\RadiusCoaService;
use App\Services\Radius\RadiusSyncService;
use App\Services\Sms\MockSmsDriver;
use App\Services\Sms\SmsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0-2, P0-3, P0-7, P0-8: Transactional Email, SMS Gateway, Card Payments & Auto-Pay Tests
 */
class CommunicationsAndCardPaymentsTest extends TestCase
{
    public function test_mushak_6_3_pdf_generation_and_email_rendering(): void
    {
        $subId = (string) Str::uuid();
        $invId = (string) Str::uuid();

        $subscriber = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => 'test_taxpayer_'.time(),
            'bandwidth_profile' => '20M/20M',
            'status' => 'active',
            'expires_at' => Carbon::now()->addDays(30),
            'created_at' => Carbon::now(),
        ]);

        $invoice = Invoice::withoutGlobalScopes()->create([
            'invoice_id' => $invId,
            'tenant_id' => 1,
            'subscriber_id' => $subId,
            'amount_bdt' => 1050.00,
            'subtotal_bdt' => 1000.00,
            'vat_rate_percent' => 5.00,
            'vat_amount_bdt' => 50.00,
            'total_bdt' => 1050.00,
            'status' => 'unpaid',
            'due_date' => Carbon::now()->addDays(5),
            'mushak_6_3_number' => 'MUSHAK-6.3-TEST-'.strtoupper(Str::random(6)),
            'created_at' => Carbon::now(),
        ]);

        $pdfService = new PdfInvoiceService;
        $html = $pdfService->renderMushak63Html($invoice);

        $this->assertStringContainsString('TAX INVOICE (MUSHAK-6.3)', $html);
        $this->assertStringContainsString('Tapos Linx Carrier ISP Ltd.', $html);
        $this->assertStringContainsString('001234567-0101', $html);
        $this->assertStringContainsString('1,050.00', $html);

        $pdfBytes = $pdfService->generatePdf($invoice);
        $this->assertNotEmpty($pdfBytes);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);

        // Verify Mail attachments
        $mail = new InvoiceGeneratedMail($invoice);
        $attachments = $mail->attachments();
        $this->assertCount(1, $attachments);
    }

    public function test_sms_gateway_and_notification_job(): void
    {
        MockSmsDriver::reset();
        $smsService = new SmsService(new MockSmsDriver);

        $res1 = $smsService->sendOtp('01711223344', '852963');
        $this->assertTrue($res1['success']);
        $this->assertCount(1, MockSmsDriver::$sentMessages);

        $res2 = $smsService->sendPaymentConfirmation('01811223344', 1500.00, 'TRX-TEST-999');
        $this->assertTrue($res2['success']);
        $this->assertCount(2, MockSmsDriver::$sentMessages);

        // Test Job
        $job = new SendSmsNotificationJob('01911223344', 'Test queue message');
        $job->handle($smsService);
        $this->assertCount(3, MockSmsDriver::$sentMessages);
    }

    public function test_sslcommerz_payment_initiation_and_ipn_settlement(): void
    {
        $subId = (string) Str::uuid();
        $invId = (string) Str::uuid();

        Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => 'sslcommerz_sub_'.time(),
            'bandwidth_profile' => '20M/20M',
            'status' => 'expired',
            'expires_at' => Carbon::now()->subDays(2),
            'created_at' => Carbon::now(),
        ]);

        Invoice::withoutGlobalScopes()->create([
            'invoice_id' => $invId,
            'tenant_id' => 1,
            'subscriber_id' => $subId,
            'amount_bdt' => 800.00,
            'subtotal_bdt' => 761.90,
            'vat_rate_percent' => 5.00,
            'vat_amount_bdt' => 38.10,
            'total_bdt' => 800.00,
            'status' => 'unpaid',
            'due_date' => Carbon::now()->addDays(3),
            'mushak_6_3_number' => 'MUSHAK-SSLC-'.strtoupper(Str::random(6)),
            'created_at' => Carbon::now(),
        ]);

        // 1. Initiate
        $initResponse = $this->postJson('/api/v1/payments/card/initiate', [
            'invoice_id' => $invId,
            'gateway' => 'sslcommerz',
        ]);

        $initResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.gateway', 'sslcommerz');

        // 2. IPN Webhook
        $tranId = 'SSLC_TEST_'.time();
        $ipnPayload = [
            'tran_id' => $tranId,
            'val_id' => 'VAL_'.time(),
            'amount' => 800.00,
            'status' => 'VALIDATED',
            'value_a' => $invId,
            'value_b' => $subId,
        ];

        $ipnResponse = $this->postJson('/api/v1/payments/card/sslcommerz/ipn', $ipnPayload);
        $ipnResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // Verify settled in DB
        $invoice = Invoice::withoutGlobalScopes()->where('invoice_id', $invId)->first();
        $this->assertEquals('paid', $invoice->status);

        $sub = Subscriber::withoutGlobalScopes()->where('subscriber_id', $subId)->first();
        $this->assertEquals('active', $sub->status);
        $this->assertTrue($sub->expires_at->isFuture());

        // 3. Test Redis SETNX replay idempotency
        $dupResponse = $this->postJson('/api/v1/payments/card/sslcommerz/ipn', $ipnPayload);
        $dupResponse->assertStatus(409)
            ->assertJsonPath('status', 'duplicate_ignored');
    }

    public function test_stripe_payment_initiation_and_webhook(): void
    {
        $subId = (string) Str::uuid();
        $invId = (string) Str::uuid();

        Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => 'stripe_sub_'.time(),
            'bandwidth_profile' => '25M/25M',
            'status' => 'expired',
            'expires_at' => Carbon::now()->subDays(1),
            'created_at' => Carbon::now(),
        ]);

        Invoice::withoutGlobalScopes()->create([
            'invoice_id' => $invId,
            'tenant_id' => 1,
            'subscriber_id' => $subId,
            'amount_bdt' => 1200.00,
            'subtotal_bdt' => 1142.86,
            'vat_rate_percent' => 5.00,
            'vat_amount_bdt' => 57.14,
            'total_bdt' => 1200.00,
            'status' => 'unpaid',
            'due_date' => Carbon::now()->addDays(3),
            'mushak_6_3_number' => 'MUSHAK-STRIPE-'.strtoupper(Str::random(6)),
            'created_at' => Carbon::now(),
        ]);

        // 1. Initiate
        $initResponse = $this->postJson('/api/v1/payments/card/initiate', [
            'invoice_id' => $invId,
            'gateway' => 'stripe',
        ]);

        $initResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.gateway', 'stripe');

        // 2. Webhook
        $eventId = 'evt_test_'.time();
        $webhookPayload = [
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_'.time(),
                    'payment_intent' => 'pi_test_'.time(),
                    'amount_total' => 120000, // 1200.00 BDT in paisa
                    'metadata' => [
                        'invoice_id' => $invId,
                        'subscriber_id' => $subId,
                    ],
                ],
            ],
        ];

        $webhookResponse = $this->postJson('/api/v1/payments/card/stripe/webhook', $webhookPayload);
        $webhookResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // Verify settled in DB
        $invoice = Invoice::withoutGlobalScopes()->where('invoice_id', $invId)->first();
        $this->assertEquals('paid', $invoice->status);

        // 3. Test duplicate idempotency
        $dupResponse = $this->postJson('/api/v1/payments/card/stripe/webhook', $webhookPayload);
        $dupResponse->assertStatus(409)
            ->assertJsonPath('status', 'duplicate_ignored');
    }

    public function test_autopay_job_processing_and_card_token(): void
    {
        $subId = (string) Str::uuid();
        $invId = (string) Str::uuid();

        $subscriber = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'username' => 'autopay_sub_'.time(),
            'bandwidth_profile' => '20M/20M',
            'status' => 'active',
            'auto_pay_enabled' => true,
            'expires_at' => Carbon::now()->subHour(),
            'created_at' => Carbon::now(),
        ]);

        $invoice = Invoice::withoutGlobalScopes()->create([
            'invoice_id' => $invId,
            'tenant_id' => 1,
            'subscriber_id' => $subId,
            'amount_bdt' => 1000.00,
            'subtotal_bdt' => 952.38,
            'vat_rate_percent' => 5.00,
            'vat_amount_bdt' => 47.62,
            'total_bdt' => 1000.00,
            'status' => 'unpaid',
            'due_date' => Carbon::now()->subMinute(),
            'mushak_6_3_number' => 'MUSHAK-AUTOPAY-'.strtoupper(Str::random(6)),
            'created_at' => Carbon::now(),
        ]);

        $token = PaymentToken::withoutGlobalScopes()->create([
            'token_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'subscriber_id' => $subId,
            'gateway' => 'stripe',
            'customer_token' => 'cus_test_'.time(),
            'payment_method_token' => 'pm_card_visa',
            'card_brand' => 'visa',
            'card_last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2028,
            'is_default' => true,
            'status' => 'active',
            'retry_count' => 0,
        ]);

        $job = new ProcessAutoPayJob;
        $result = $job->handle(
            app(DoubleEntryLedgerService::class),
            app(RadiusSyncService::class),
            app(RadiusCoaService::class),
            app(BillingAuditService::class),
            new SmsService(new MockSmsDriver)
        );

        $this->assertGreaterThanOrEqual(1, $result['processed']);
        $this->assertGreaterThanOrEqual(1, $result['succeeded']);

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);

        $token->refresh();
        $this->assertEquals(0, $token->retry_count);
    }
}
