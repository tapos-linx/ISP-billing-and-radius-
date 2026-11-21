<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\Tariff;
use App\Services\Audit\AuditChainVerificationService;
use App\Services\Audit\BillingAuditService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 (P2 Batch 3): High Availability, Banking Clearing, Anycast Topology & Disaster Recovery Feature Test
 */
class HaAndBankingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_beftn_direct_debit_clearing_batch_flow_and_reconciliation(): void
    {
        // 1. Create Subscriber & Unpaid Invoice
        $tariff = Tariff::withoutGlobalScopes()->first();
        $tariffId = $tariff ? (int) $tariff->tariff_id : 1;

        /** @var Subscriber $sub */
        $sub = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'username' => 'bank_client_'.Str::lower(Str::random(5)),
            'password' => 'BankSecret123',
            'tariff_id' => $tariffId,
            'status' => 'active',
            'expiry_date' => Carbon::now()->addMonth(),
            'auto_pay_enabled' => true,
            'pro_rata_balance' => 0.0,
        ]);

        /** @var Invoice $invoice */
        $invoice = Invoice::withoutGlobalScopes()->create([
            'invoice_id' => (string) Str::uuid(),
            'subscriber_id' => $sub->subscriber_id,
            'tenant_id' => 1,
            'amount_bdt' => 1500.00,
            'subtotal_bdt' => 1428.57,
            'vat_rate_percent' => 5.00,
            'vat_amount_bdt' => 71.43,
            'total_bdt' => 1500.00,
            'status' => 'unpaid',
            'due_date' => Carbon::now()->addDays(5),
            'mushak_6_3_number' => 'MUSHAK-6.3-'.strtoupper(Str::random(8)),
        ]);

        // 2. Generate BEFTN Clearing Batch
        $genResp = $this->postJson('/api/v1/clearing/batches', [
            'tenant_id' => 1,
            'clearing_type' => 'BEFTN',
            'invoice_ids' => [(string) $invoice->invoice_id],
            'originator_info' => [
                'bank_code' => '010',
                'routing_no' => '010271234',
                'account_no' => '100123456789',
                'name' => 'Metro Fiber ISP Ltd',
            ],
            'settlement_date' => Carbon::tomorrow()->toDateString(),
        ]);

        $genResp->assertStatus(201);
        $batchId = $genResp->json('batch.id');
        $this->assertNotNull($batchId);
        $this->assertEquals('pending', $genResp->json('batch.status'));
        $this->assertEquals(1, $genResp->json('batch.total_count'));
        $this->assertEquals(1500.00, (float) $genResp->json('batch.total_amount_bdt'));

        // 3. Export ISO 20022 XML Batch File
        $exportResp = $this->get("/api/v1/clearing/batches/{$batchId}/export");
        $exportResp->assertStatus(200);
        $exportResp->assertHeader('Content-Type', 'application/xml');
        $xmlContent = $exportResp->getContent();
        $this->assertStringContainsString('<CstmrDrctDbtInitn>', $xmlContent);
        $this->assertStringContainsString('Metro Fiber ISP Ltd', $xmlContent);
        $this->assertStringContainsString('1500.00', $xmlContent);

        // Fetch transaction instruction ID from batch
        $instructionId = DB::table('beftn_transactions')
            ->where('batch_id', $batchId)
            ->value('instruction_id');
        $this->assertNotNull($instructionId);

        // 4. Process Settlement Return (Bank confirmation code '00')
        $returnResp = $this->postJson("/api/v1/clearing/batches/{$batchId}/returns", [
            'returns' => [
                [
                    'instruction_id' => $instructionId,
                    'return_code' => '00',
                ],
            ],
        ]);

        $returnResp->assertStatus(200);
        $this->assertEquals(1, $returnResp->json('summary.settled_count'));
        $this->assertEquals(0, $returnResp->json('summary.rejected_count'));
        $this->assertEquals(1500.00, (float) $returnResp->json('summary.total_settled_amount'));
        $this->assertEquals('settled', $returnResp->json('summary.status'));

        // 5. Verify Invoice Paid & Payment Recorded
        $refreshedInvoice = Invoice::withoutGlobalScopes()->where('invoice_id', $invoice->invoice_id)->first();
        $this->assertNotNull($refreshedInvoice);
        $this->assertEquals('paid', $refreshedInvoice->status);
        $this->assertNotNull($refreshedInvoice->paid_at);

        $payment = Payment::withoutGlobalScopes()->where('invoice_id', $invoice->invoice_id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals('beftn', $payment->gateway);
        $this->assertEquals(1500.00, (float) $payment->amount_bdt);

        // 6. Verify Double-Entry Journal (DR 1010 Clearing / CR 1100 AR)
        $ledgerCount = LedgerEntry::where('invoice_id', $invoice->invoice_id)->count();
        $this->assertGreaterThanOrEqual(2, $ledgerCount);
    }

    public function test_anycast_radius_node_lifecycle_health_telemetry_and_auto_drain(): void
    {
        $nodeCode = 'ANYCAST-DHK-'.strtoupper(Str::random(4));

        // 1. Register Anycast AAA Node
        $regResp = $this->postJson('/api/v1/anycast/nodes', [
            'tenant_id' => 1,
            'node_code' => $nodeCode,
            'region' => 'dhaka-central',
            'pop_name' => 'Dhaka Core POP 1',
            'vip_ipv4' => '103.145.10.1',
            'physical_ip' => '172.16.1.10',
            'bgp_asn' => 65001,
            'bgp_community' => '65001:100',
            'weight' => 100,
        ]);

        $regResp->assertStatus(201);
        $this->assertEquals('active', $regResp->json('node.status'));
        $this->assertTrue($regResp->json('node.is_announced'));

        // 2. Ingest Healthy Telemetry (Auth 4.2ms, Acct 5.5ms, Repl lag 0.1s, BGP UP)
        $healthResp = $this->postJson("/api/v1/anycast/nodes/{$nodeCode}/health", [
            'auth_latency_ms' => 4.2,
            'acct_latency_ms' => 5.5,
            'replication_lag_seconds' => 0.1,
            'bgp_session_up' => true,
            'packet_loss_pct' => 0.0,
        ]);

        $healthResp->assertStatus(200);
        $this->assertTrue($healthResp->json('health_check.is_healthy'));

        // 3. Ingest Degraded Telemetry (Auth 65ms, Repl lag 14s, BGP DOWN) -> Triggers Automated Route Withdrawal
        $degradedResp = $this->postJson("/api/v1/anycast/nodes/{$nodeCode}/health", [
            'auth_latency_ms' => 65.0,
            'acct_latency_ms' => 80.0,
            'replication_lag_seconds' => 14.0,
            'bgp_session_up' => false,
            'packet_loss_pct' => 5.0,
        ]);

        $degradedResp->assertStatus(200);
        $this->assertFalse($degradedResp->json('health_check.is_healthy'));

        // Check node status updated to draining & withdrawn from BGP
        $node = DB::table('anycast_nodes')->where('node_code', $nodeCode)->first();
        $this->assertNotNull($node);
        $this->assertEquals('draining', $node->status);
        $this->assertFalse($node->is_announced);

        // 4. Re-announce Node Route Manually
        $announceResp = $this->postJson("/api/v1/anycast/nodes/{$nodeCode}/announce");
        $announceResp->assertStatus(200);
        $this->assertEquals('active', $announceResp->json('node.status'));
        $this->assertTrue($announceResp->json('node.is_announced'));

        // 5. Query Global Topology Overview
        $topoResp = $this->getJson('/api/v1/anycast/status');
        $topoResp->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $topoResp->json('total_nodes'));
        $this->assertArrayHasKey('dhaka-central', $topoResp->json('regional_breakdown'));
    }

    public function test_disaster_recovery_failover_drill_execution_and_carrier_metrics(): void
    {
        // 1. Execute Simulated Primary DC Loss DR Drill
        $drillResp = $this->postJson('/api/v1/dr/drills', [
            'drill_type' => 'simulated_primary_dc_loss',
            'initiated_by' => 'lead_noc_architect',
            'primary_region' => 'dc1-dhaka',
            'secondary_region' => 'dc2-chattogram',
            'simulated_rto_seconds' => 9.65,
            'simulated_rpo_seconds' => 2.10,
        ]);

        $drillResp->assertStatus(201);
        $drillId = $drillResp->json('drill.id');
        $this->assertNotNull($drillId);
        $this->assertEquals('passed', $drillResp->json('drill.status'));
        $this->assertEquals(9.65, (float) $drillResp->json('drill.rto_seconds'));
        $this->assertEquals(2.10, (float) $drillResp->json('drill.rpo_seconds'));
        $this->assertTrue($drillResp->json('drill.sentinel_failover_verified'));
        $this->assertTrue($drillResp->json('drill.replica_promotion_verified'));
        $this->assertTrue($drillResp->json('drill.s3_wal_restored'));
        $this->assertTrue($drillResp->json('drill.anycast_diverted'));
        $this->assertNotNull($drillResp->json('drill.audit_hash'));

        // 2. Verify Drill Recorded in History
        $historyResp = $this->getJson('/api/v1/dr/drills');
        $historyResp->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $historyResp->json('total'));
    }

    public function test_tamper_evident_audit_log_hash_chain_verification_and_tamper_detection(): void
    {
        /** @var BillingAuditService $auditService */
        $auditService = app(BillingAuditService::class);
        /** @var AuditChainVerificationService $verifyService */
        $verifyService = app(AuditChainVerificationService::class);

        // 1. Log sequential events establishing a cryptographic hash chain
        $auditService->logEvent('invoice.generated', 'invoice', (string) Str::uuid(), 'billing_worker');
        $auditService->logEvent('payment.captured', 'payment', (string) Str::uuid(), 'mfs_webhook');
        $auditService->logEvent('radius.coa_triggered', 'subscriber', (string) Str::uuid(), 'radius_sync');

        // 2. Verify Clean Hash Chain via API
        $verifyResp = $this->getJson('/api/v1/audit/verify-chain');
        $verifyResp->assertStatus(200);
        $this->assertTrue($verifyResp->json('is_valid'));
        $this->assertEmpty($verifyResp->json('tampered_rows'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $verifyResp->json('chain_root_hash'));

        // 3. Verify Database Engine Immutability: direct UPDATE is blocked by PostgreSQL trigger
        $latestLog = DB::table('billing_audit_logs')->orderByDesc('id')->first();
        $this->assertNotNull($latestLog);

        $triggerBlocked = false;
        DB::statement('SAVEPOINT audit_tamper_sp');
        try {
            DB::table('billing_audit_logs')
                ->where('id', $latestLog->id)
                ->update(['prev_hash' => str_repeat('f', 64)]);
        } catch (QueryException $e) {
            DB::statement('ROLLBACK TO SAVEPOINT audit_tamper_sp');
            $triggerBlocked = true;
            $this->assertStringContainsString('strictly append-only', $e->getMessage());
        }
        $this->assertTrue($triggerBlocked, 'PostgreSQL trigger trg_protect_billing_audit_logs must prevent update');

        // 4. Simulate Forensic Tamper: append a forged log with an invalid prev_hash
        $forgedLog = $verifyService->insertForgedRecord(
            str_repeat('e', 64),
            hash('sha256', 'forged_payload')
        );

        // 5. Verify Forensic Tamper is Detected
        $tamperedCheck = $verifyService->verifyChain();
        $this->assertFalse($tamperedCheck['is_valid']);
        $this->assertNotEmpty($tamperedCheck['tampered_rows']);
        $this->assertEquals($forgedLog->id, $tamperedCheck['tampered_rows'][0]['id']);
    }
}
