<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Radius\RadiusCoaService;
use App\Services\Radius\RadiusSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class EndToEndMfsSettlementAndRadiusCoaTest extends TestCase
{
    private string $subscriberId = 'e2e11111-2222-3333-4444-555566667777';
    private string $invoiceId = 'fa111111-2222-3333-4444-555566667777';
    private string $username = 'tapos_e2e_sub';
    private string $trxId;
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trxId = 'TRX_BKASH_E2E_' . bin2hex(random_bytes(6));
        $this->secret = (string) (config('services.bkash.webhook_secret')
            ?: (config('services.bkash.app_secret')
            ?: env('BKASH_WEBHOOK_SECRET', env('BKASH_APP_SECRET', 'local-test-bkash-secret-123456'))));

        $this->cleanUpTestData();
        $this->seedExpiredSubscriber();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTestData();
        parent::tearDown();
    }

    private function cleanUpTestData(): void
    {
        DB::table('payment_webhooks')->where('subscriber_id', $this->subscriberId)->delete();
        DB::table('btrc_audit_logs')->where('subscriber_id', $this->subscriberId)->delete();
        DB::table('failed_coa_jobs')->where('subscriber_id', $this->subscriberId)->delete();
        DB::table('radcheck')->where('subscriber_id', $this->subscriberId)->delete();
        DB::table('radreply')->where('subscriber_id', $this->subscriberId)->delete();
        DB::table('invoices')->where('subscriber_id', $this->subscriberId)->delete();
        DB::table('subscribers')->where('subscriber_id', $this->subscriberId)->delete();

        Redis::del("radius:auth:{$this->username}");
    }

    private function seedExpiredSubscriber(): void
    {
        // 1. Seed subscriber in expired state
        DB::table('subscribers')->insert([
            'subscriber_id'     => $this->subscriberId,
            'tenant_id'         => 1,
            'username'          => $this->username,
            'status'            => 'expired',
            'bandwidth_profile' => '20M/20M',
            'expires_at'        => now()->subDays(2),
            'created_at'        => now()->subMonths(1),
        ]);

        // 2. Seed radcheck with cleartext password
        DB::table('radcheck')->insert([
            'subscriber_id' => $this->subscriberId,
            'username'      => $this->username,
            'attribute'     => 'Cleartext-Password',
            'op'            => ':=',
            'value'         => 'E2ePass@2026',
        ]);

        // 3. Seed radreply in walled_garden_pool with restricted rate-limit
        DB::table('radreply')->insert([
            [
                'subscriber_id' => $this->subscriberId,
                'username'      => $this->username,
                'attribute'     => 'Mikrotik-Rate-Limit',
                'op'            => ':=',
                'value'         => '128k/128k',
            ],
            [
                'subscriber_id' => $this->subscriberId,
                'username'      => $this->username,
                'attribute'     => 'Framed-Pool',
                'op'            => ':=',
                'value'         => 'walled_garden_pool',
            ],
        ]);

        // 4. Seed unpaid NBR 5% VAT Mushak-6.3 Invoice
        DB::table('invoices')->insert([
            'invoice_id'        => $this->invoiceId,
            'subscriber_id'     => $this->subscriberId,
            'tenant_id'         => 1,
            'subtotal_bdt'      => 1000.00,
            'vat_rate_percent'  => 5.00,
            'vat_amount_bdt'    => 50.00,
            'total_bdt'         => 1050.00,
            'amount_bdt'        => 1050.00,
            'status'            => 'unpaid',
            'isp_bin_number'    => 'BIN-001234567-0101',
            'mushak_6_3_number' => 'MUSHAK-6.3-2026-E2E01',
            'customer_bin_nid'  => 'NID-19901234567890',
            'due_date'          => now()->subDays(2),
            'paid_at'           => null,
            'created_at'        => now()->subDays(5),
        ]);
    }

    public function test_end_to_end_mfs_settlement_restores_subscriber_and_dispatches_coa(): void
    {
        // -------------------------------------------------------------
        // Step 1: Pre-condition assertions (subscriber is in walled garden)
        // -------------------------------------------------------------
        $prePool = DB::table('radreply')
            ->where('subscriber_id', $this->subscriberId)
            ->where('attribute', 'Framed-Pool')
            ->value('value');
        $this->assertSame('walled_garden_pool', $prePool);

        $preRate = DB::table('radreply')
            ->where('subscriber_id', $this->subscriberId)
            ->where('attribute', 'Mikrotik-Rate-Limit')
            ->value('value');
        $this->assertSame('128k/128k', $preRate);

        $preInvoice = DB::table('invoices')->where('invoice_id', $this->invoiceId)->first();
        $this->assertSame('unpaid', $preInvoice->status);
        $this->assertNull($preInvoice->paid_at);

        // -------------------------------------------------------------
        // Step 2: Post signed bKash MFS Webhook with HMAC-SHA256 & Nonce
        // -------------------------------------------------------------
        $payload = [
            'trx_id'        => $this->trxId,
            'subscriber_id' => $this->subscriberId,
            'invoice_id'    => $this->invoiceId,
            'amount_bdt'    => 1050.00,
        ];
        $rawJson = json_encode($payload);
        $timestamp = time();
        $nonce = 'nonce_e2e_' . bin2hex(random_bytes(10));
        $signature = hash_hmac('sha256', $rawJson, $this->secret);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/bkash',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'           => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_WEBHOOK_NONCE'     => $nonce,
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawJson
        );

        // Verify 202 Accepted
        $response->assertStatus(202);
        $this->assertSame('accepted', $response->json('status'));
        $this->assertSame($this->trxId, $response->json('trx_id'));

        // -------------------------------------------------------------
        // Step 3: Verify Duplicate Nonce Replay is Rejected (409 Conflict)
        // -------------------------------------------------------------
        $replayResponse = $this->call(
            'POST',
            '/api/v1/webhooks/bkash',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'           => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_WEBHOOK_NONCE'     => $nonce, // Reused nonce
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawJson
        );

        $replayResponse->assertStatus(409);
        $this->assertStringContainsString('nonce already used', (string) $replayResponse->json('error'));

        // -------------------------------------------------------------
        // Step 4: Verify Duplicate trx_id Replay is Rejected (409 Conflict / Idempotency)
        // -------------------------------------------------------------
        $newNonce = 'nonce_e2e_fresh_' . bin2hex(random_bytes(10));
        $freshTimestamp = time();
        $duplicateTrxResponse = $this->call(
            'POST',
            '/api/v1/webhooks/bkash',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'           => 'application/json',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $freshTimestamp,
                'HTTP_X_WEBHOOK_NONCE'     => $newNonce, // Fresh nonce
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            $rawJson // Same trx_id
        );

        $duplicateTrxResponse->assertStatus(409);
        $this->assertSame('duplicate_ignored', $duplicateTrxResponse->json('status'));

        // -------------------------------------------------------------
        // Step 5: Verify Citus NBR 5% VAT Mushak-6.3 Invoice is Marked Paid
        // -------------------------------------------------------------
        $settledInvoice = DB::table('invoices')->where('invoice_id', $this->invoiceId)->first();
        $this->assertNotNull($settledInvoice);
        $this->assertSame('paid', $settledInvoice->status);
        $this->assertNotNull($settledInvoice->paid_at);
        $this->assertEquals(1000.00, (float) $settledInvoice->subtotal_bdt);
        $this->assertEquals(5.00, (float) $settledInvoice->vat_rate_percent);
        $this->assertEquals(50.00, (float) $settledInvoice->vat_amount_bdt);
        $this->assertEquals(1050.00, (float) $settledInvoice->total_bdt);
        $this->assertSame('MUSHAK-6.3-2026-E2E01', $settledInvoice->mushak_6_3_number);
        $this->assertSame('BIN-001234567-0101', $settledInvoice->isp_bin_number);

        // -------------------------------------------------------------
        // Step 6: Verify Subscriber Status and Expiration Extended (+30 days)
        // -------------------------------------------------------------
        $activeSubscriber = DB::table('subscribers')->where('subscriber_id', $this->subscriberId)->first();
        $this->assertNotNull($activeSubscriber);
        $this->assertSame('active', $activeSubscriber->status);
        $this->assertTrue(strtotime($activeSubscriber->expires_at) > (time() + 25 * 86400));

        // -------------------------------------------------------------
        // Step 7: Verify Citus radreply Restored to Active Rate Limit & Active Pool
        // -------------------------------------------------------------
        $restoredPool = DB::table('radreply')
            ->where('subscriber_id', $this->subscriberId)
            ->where('attribute', 'Framed-Pool')
            ->value('value');
        $this->assertSame('pppoe_active_pool', $restoredPool);

        $restoredRate = DB::table('radreply')
            ->where('subscriber_id', $this->subscriberId)
            ->where('attribute', 'Mikrotik-Rate-Limit')
            ->value('value');
        $this->assertSame('20M/20M', $restoredRate);

        // -------------------------------------------------------------
        // Step 8: Verify Redis Fast-Path Cache Warmed
        // -------------------------------------------------------------
        $fastPathCached = Redis::get("radius:auth:{$this->username}");
        $this->assertNotNull($fastPathCached);
        $cacheData = json_decode((string) $fastPathCached, true);
        $this->assertSame('pppoe_active_pool', $cacheData['Framed-Pool']);
        $this->assertSame('20M/20M', $cacheData['Mikrotik-Rate-Limit']);

        // -------------------------------------------------------------
        // Step 9: Verify BTRC Audit Retention Log Created
        // -------------------------------------------------------------
        $auditLog = DB::table('btrc_audit_logs')
            ->where('subscriber_id', $this->subscriberId)
            ->where('action', 'activate_after_payment')
            ->first();
        $this->assertNotNull($auditLog);
        $this->assertSame('mfs_webhook', $auditLog->actor_type);
        $this->assertTrue(strtotime($auditLog->retained_until) > (time() + 700 * 86400));
    }

    public function test_self_care_portal_endpoints(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        // 1. Test GET /self-care JSON representation
        $response = $this->getJson("/self-care?subscriber_id={$this->subscriberId}");
        $response->assertStatus(200);
        $response->assertJsonPath('subscriber.username', $this->username);
        $response->assertJsonPath('latestInvoice.mushak_6_3_number', 'MUSHAK-6.3-2026-E2E01');
        $response->assertJsonPath('latestInvoice.total_bdt', 1050);

        // 2. Test POST /self-care/pay/bkash
        $payRes = $this->postJson('/self-care/pay/bkash', [
            'invoice_id' => $this->invoiceId,
        ]);
        $payRes->assertStatus(200);
        $payRes->assertJsonPath('status', 'redirect_to_gateway');
        $payRes->assertJsonPath('gateway', 'bkash');
        $this->assertStringContainsString('https://checkout.sandbox.bkash.com', (string) $payRes->json('checkout_url'));

        // 3. Test POST /two-factor-challenge
        $twoFaRes = $this->postJson('/two-factor-challenge', [
            'code' => '123456',
        ]);
        $twoFaRes->assertStatus(200);
        $twoFaRes->assertJsonPath('status', 'authenticated');

        // 4. Test POST /two-factor-challenge with invalid code '000000'
        $invalidTwoFa = $this->postJson('/two-factor-challenge', [
            'code' => '000000',
        ]);
        $invalidTwoFa->assertStatus(422);

        // 5. Test POST /self-care/2fa/toggle
        $toggleRes = $this->postJson('/self-care/2fa/toggle');
        $toggleRes->assertStatus(200);
        $toggleRes->assertJsonPath('status', 'toggled');
    }

    public function test_rfc5176_coa_dead_letter_logging(): void
    {
        $coaService = app(RadiusCoaService::class);
        $coaService->recordDeadLetter(
            $this->subscriberId,
            $this->username,
            '10.10.0.1',
            'disconnect',
            'Connection timed out after 3000ms'
        );

        $deadLetter = DB::table('failed_coa_jobs')
            ->where('subscriber_id', $this->subscriberId)
            ->where('action', 'disconnect')
            ->first();

        $this->assertNotNull($deadLetter);
        $this->assertSame($this->username, $deadLetter->username);
        $this->assertSame('10.10.0.1', $deadLetter->nas_ip);
        $this->assertStringContainsString('timed out', $deadLetter->error_reason);
    }
}
