<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 (P1 Batch 4): FinOps, Cashdesk POS, Omnichannel Comms, Webhooks & Executive BI Feature Test
 */
class OmnichannelAndBiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_bank_statement_csv_reconciliation_and_ledger_settlement(): void
    {
        // 1. Create a subscriber and an outstanding invoice
        $tariff = Tariff::withoutGlobalScopes()->first();
        $tariffId = $tariff ? (int) $tariff->tariff_id : 1;

        /** @var Subscriber $sub */
        $sub = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'username' => 'bank_sub_'.Str::lower(Str::random(6)),
            'password' => 'Secret123',
            'tariff_id' => $tariffId,
            'status' => 'suspended', // Initially suspended for non-payment
            'expiry_date' => Carbon::now()->subDays(2),
            'auto_pay_enabled' => false,
            'pro_rata_balance' => 0.0,
        ]);

        /** @var Invoice $invoice */
        $invoice = Invoice::withoutGlobalScopes()->create([
            'invoice_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'subscriber_id' => $sub->subscriber_id,
            'amount_bdt' => 1500.00,
            'subtotal_bdt' => 1428.57,
            'vat_rate_percent' => 5.0,
            'vat_amount_bdt' => 71.43,
            'total_bdt' => 1500.00,
            'status' => 'issued',
            'due_date' => Carbon::now()->subDays(5),
        ]);

        // 2. Ingest Bank Statement feed
        $stmtResp = $this->postJson('/api/v1/finops/bank-statements', [
            'tenant_id' => 1,
            'bank_name' => 'BRAC Bank Limited',
            'account_number' => '15012034980001',
            'statement_date' => Carbon::now()->format('Y-m-d'),
            'lines' => [
                [
                    'amount_bdt' => 1500.00,
                    'reference_number' => $invoice->invoice_id,
                    'description' => "EFT Transfer for Invoice {$invoice->invoice_id}",
                    'date' => Carbon::now()->format('Y-m-d'),
                ],
            ],
        ]);
        $stmtResp->assertStatus(201);
        $stmtId = $stmtResp->json('data.statement_id');
        $this->assertNotNull($stmtId);

        // 3. Auto-reconcile statement
        $reconResp = $this->postJson("/api/v1/finops/bank-statements/{$stmtId}/auto-reconcile");
        $reconResp->assertStatus(200);
        $this->assertEquals(1, $reconResp->json('data.reconciled'));

        // 4. Verify invoice is paid and subscriber is unblocked
        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        $sub->refresh();
        $this->assertEquals('active', $sub->status);

        // 5. Verify double-entry ledger entry was posted
        $ledgerCount = LedgerEntry::where('invoice_id', $invoice->invoice_id)
            ->where('description', 'like', '%Bank Reconciliation%')
            ->count();
        $this->assertGreaterThanOrEqual(2, $ledgerCount);
    }

    public function test_cashier_pos_shift_workflow_collection_and_drawer_audit(): void
    {
        // 1. Get or create cashier user
        $user = User::withoutGlobalScopes()->first();
        $userId = $user ? (int) $user->id : 1;

        // 2. Open Cashier Shift with starting float
        $shiftResp = $this->postJson('/api/v1/cashdesk/shifts/open', [
            'tenant_id' => 1,
            'cashier_user_id' => $userId,
            'starting_cash_bdt' => 2000.00,
        ]);
        $shiftResp->assertStatus(201);
        $shiftId = $shiftResp->json('data.shift_id');
        $this->assertNotNull($shiftId);
        $this->assertEquals('open', $shiftResp->json('data.status'));
        $this->assertEquals(2000.00, $shiftResp->json('data.starting_cash_bdt'));

        // 3. Create subscriber and invoice for POS payment
        $tariff = Tariff::withoutGlobalScopes()->first();
        $tariffId = $tariff ? (int) $tariff->tariff_id : 1;

        /** @var Subscriber $sub */
        $sub = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'username' => 'pos_sub_'.Str::lower(Str::random(6)),
            'password' => 'CashPass1',
            'tariff_id' => $tariffId,
            'status' => 'suspended',
            'expiry_date' => Carbon::now()->subDay(),
            'auto_pay_enabled' => false,
            'pro_rata_balance' => 0.0,
        ]);

        /** @var Invoice $invoice */
        $invoice = Invoice::withoutGlobalScopes()->create([
            'invoice_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'subscriber_id' => $sub->subscriber_id,
            'amount_bdt' => 1000.00,
            'subtotal_bdt' => 952.38,
            'vat_rate_percent' => 5.0,
            'vat_amount_bdt' => 47.62,
            'total_bdt' => 1000.00,
            'status' => 'issued',
            'due_date' => Carbon::now()->subDays(3),
        ]);

        // 4. Collect retail cash payment at POS
        $collectResp = $this->postJson("/api/v1/cashdesk/shifts/{$shiftId}/collect", [
            'subscriber_id' => $sub->subscriber_id,
            'invoice_id' => $invoice->invoice_id,
            'amount_bdt' => 1000.00,
        ]);
        $collectResp->assertStatus(201);
        $this->assertNotNull($collectResp->json('data.receipt_number'));
        $this->assertEquals(1000.00, $collectResp->json('data.amount_bdt'));

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $sub->refresh();
        $this->assertEquals('active', $sub->status);

        // 5. Close Cashier Shift with counted physical cash (Expected: 2000 + 1000 = 3000)
        $closeResp = $this->postJson("/api/v1/cashdesk/shifts/{$shiftId}/close", [
            'closing_cash_bdt' => 3000.00,
            'notes' => 'Evening shift closed without discrepancy',
        ]);
        $closeResp->assertStatus(200);
        $this->assertEquals('closed', $closeResp->json('data.status'));
        $this->assertEquals(0.00, $closeResp->json('data.discrepancy_bdt'));
        $this->assertNotNull($closeResp->json('data.closed_at'));
    }

    public function test_omnichannel_whatsapp_and_telegram_alerts(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'messages' => [['id' => 'wamid.HBgLM...']],
            ], 200),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 99182],
            ], 200),
        ]);

        // 1. WhatsApp notification dispatch
        $waResp = $this->postJson('/api/v1/notifications/whatsapp', [
            'phone' => '+8801700000000',
            'template_name' => 'bill_payment_confirmation',
            'parameters' => ['INV-9901', '1000 BDT'],
        ]);
        $waResp->assertStatus(200);
        $this->assertTrue($waResp->json('success'));
        $this->assertEquals('whatsapp', $waResp->json('channel'));

        // 2. Telegram Bot alert dispatch
        $tgResp = $this->postJson('/api/v1/notifications/telegram', [
            'chat_id' => '-1001234567890',
            'message' => '*NOC ALERT*: Core OLT Banani-01 fiber port restored.',
        ]);
        $tgResp->assertStatus(200);
        $this->assertTrue($tgResp->json('success'));
        $this->assertEquals('telegram', $tgResp->json('channel'));
    }

    public function test_outbound_webhook_subscription_and_hmac_sha256_dispatch(): void
    {
        Http::fake([
            'https://partner-portal.carrier.bd/webhooks' => Http::response(['received' => true], 200),
        ]);

        $secret = 'super_secret_webhook_key_2026';

        // 1. Register Webhook Subscription
        $subResp = $this->postJson('/api/v1/webhooks/subscriptions', [
            'tenant_id' => 1,
            'target_url' => 'https://partner-portal.carrier.bd/webhooks',
            'events' => ['invoice.paid', 'subscriber.activated'],
            'secret' => $secret,
        ]);
        $subResp->assertStatus(201);
        $this->assertNotNull($subResp->json('data.subscription_id'));

        // 2. Dispatch Outbound Event
        $payload = [
            'invoice_id' => (string) Str::uuid(),
            'amount_bdt' => 1250.00,
            'payment_method' => 'bkash',
            'timestamp' => Carbon::now()->toIso8601String(),
        ];

        $dispResp = $this->postJson('/api/v1/webhooks/dispatch', [
            'tenant_id' => 1,
            'event_type' => 'invoice.paid',
            'payload' => $payload,
        ]);
        $dispResp->assertStatus(200);
        $this->assertNotEmpty($dispResp->json('deliveries'));
        $this->assertEquals('success', $dispResp->json('deliveries.0.status'));
        $this->assertEquals(200, $dispResp->json('deliveries.0.response_code'));

        // 3. Verify HTTP request contained cryptographic HMAC-SHA256 signature header
        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Carrier-Signature') &&
                str_starts_with($request->header('X-Carrier-Signature')[0], 'sha256=') &&
                $request->hasHeader('X-Carrier-Event', 'invoice.paid');
        });
    }

    public function test_executive_bi_mrr_arpu_churn_and_ar_aging_buckets(): void
    {
        $resp = $this->getJson('/api/v1/analytics/bi-overview?tenant_id=1');
        $resp->assertStatus(200);

        $data = $resp->json('data');
        $this->assertEquals(1, $data['tenant_id']);
        $this->assertArrayHasKey('active_subscribers', $data);
        $this->assertArrayHasKey('total_subscribers', $data);
        $this->assertArrayHasKey('churn_rate_pct', $data);
        $this->assertArrayHasKey('mrr_bdt', $data);
        $this->assertArrayHasKey('arpu_bdt', $data);

        // Verify AR Aging cohorts structure
        $arAging = $data['ar_aging'];
        $this->assertArrayHasKey('current_0_30_days_bdt', $arAging);
        $this->assertArrayHasKey('overdue_31_60_days_bdt', $arAging);
        $this->assertArrayHasKey('overdue_61_90_days_bdt', $arAging);
        $this->assertArrayHasKey('overdue_90_plus_days_bdt', $arAging);
        $this->assertArrayHasKey('total_outstanding_ar_bdt', $arAging);
        $this->assertGreaterThanOrEqual(0.0, $arAging['total_outstanding_ar_bdt']);
    }
}
