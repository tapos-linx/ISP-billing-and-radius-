<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\EnterpriseCircuit;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Subscriber;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 (P2 Batch 1): Enterprise SLA, Wholesale Carrier, 95th Percentile & VoIP Rating Feature Test
 */
class EnterpriseAndVoipTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_enterprise_circuit_registration_and_automated_sla_breach_penalty(): void
    {
        // 1. Create Subscriber
        $tariff = Tariff::withoutGlobalScopes()->first();
        $tariffId = $tariff ? (int) $tariff->tariff_id : 1;

        /** @var Subscriber $sub */
        $sub = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'username' => 'corp_client_'.Str::lower(Str::random(5)),
            'password' => 'CorpSecret99',
            'tariff_id' => $tariffId,
            'status' => 'active',
            'expiry_date' => Carbon::now()->addMonth(),
            'auto_pay_enabled' => false,
            'pro_rata_balance' => 0.0,
        ]);

        // 2. Register Enterprise Circuit (Committed 100 Mbps, 99.90% SLA, BDT 50,000/mo)
        $circuitRef = 'CKT-DHAKA-'.strtoupper(Str::random(6));
        $circuitResp = $this->postJson('/api/v1/enterprise/circuits', [
            'tenant_id' => 1,
            'subscriber_id' => $sub->subscriber_id,
            'circuit_ref' => $circuitRef,
            'committed_bandwidth_mbps' => 100,
            'sla_target_availability_pct' => 99.90,
            'max_latency_ms' => 15,
            'max_jitter_ms' => 3,
            'max_packet_loss_pct' => 0.05,
            'monthly_rate_bdt' => 50000.00,
        ]);
        $circuitResp->assertStatus(201);
        $circuitId = $circuitResp->json('data.circuit_id');
        $this->assertNotNull($circuitId);
        $this->assertEquals(99.90, $circuitResp->json('data.sla_target_availability_pct'));

        // 3. Audit SLA for month with 600 minutes of downtime (42,600 / 43,200 = 98.61% availability -> breach > 1% diff -> 20% penalty credit)
        $auditResp = $this->postJson("/api/v1/enterprise/circuits/{$circuitId}/audit-sla", [
            'billing_month' => '2026-11',
            'downtime_minutes' => 600,
        ]);
        $auditResp->assertStatus(200);
        $this->assertEquals(98.61, $auditResp->json('data.actual_availability_pct'));
        $this->assertEquals('credited', $auditResp->json('data.status'));
        $this->assertGreaterThan(0.0, $auditResp->json('data.penalty_credit_bdt'));

        // Penalty 20% of 50,000 = 10,000 BDT
        $this->assertEquals(10000.00, $auditResp->json('data.penalty_credit_bdt'));
        $creditNoteId = $auditResp->json('data.credit_note_id');
        $this->assertNotNull($creditNoteId);

        // Verify CreditNote was created
        $cn = CreditNote::withoutGlobalScopes()->find($creditNoteId);
        $this->assertNotNull($cn);
        $this->assertEquals(10000.00, $cn->total_bdt);

        // Verify double-entry ledger entry was posted (DR 4000 / CR 1100)
        $ledgerCount = LedgerEntry::where('description', 'like', "%{$circuitRef}%")->count();
        $this->assertGreaterThanOrEqual(2, $ledgerCount);
    }

    public function test_wholesale_carrier_l2tp_tunnel_and_monthly_trunk_invoicing(): void
    {
        // 1. Register Wholesale Carrier Partner
        $code = 'CARRIER_'.strtoupper(Str::random(4));
        $carrierResp = $this->postJson('/api/v1/wholesale/carriers', [
            'tenant_id' => 1,
            'carrier_name' => 'Metro Fiber Interconnect Ltd',
            'carrier_code' => $code,
            'account_number' => 'ACC-METRO-0091',
            'rate_per_mbps_bdt' => 120.00,
        ]);
        $carrierResp->assertStatus(201);
        $carrierId = $carrierResp->json('data.carrier_id');
        $this->assertNotNull($carrierId);

        // 2. Provision Wholesale L2TP LAC/LNS Tunnel
        $tunnelResp = $this->postJson("/api/v1/wholesale/carriers/{$carrierId}/tunnels", [
            'tunnel_name' => 'L2TP-METRO-PRIMARY',
            'local_endpoint_ip' => '103.145.110.1',
            'remote_endpoint_ip' => '103.145.112.5',
            'shared_secret' => 'CarrierRadiusTunnelSecret2026!',
            'committed_mbps' => 2500,
        ]);
        $tunnelResp->assertStatus(201);
        $this->assertEquals('up', $tunnelResp->json('data.status'));
        $this->assertEquals(2500, $tunnelResp->json('data.committed_mbps'));

        // 3. Generate Monthly Wholesale Trunk Invoice (e.g. 2,000 Mbps * 120 BDT = 240,000 BDT + 5% VAT)
        $invResp = $this->postJson("/api/v1/wholesale/carriers/{$carrierId}/invoice", [
            'billed_mbps' => 2000,
        ]);
        $invResp->assertStatus(201);
        $invoiceId = $invResp->json('data.invoice_id');
        $this->assertNotNull($invoiceId);
        $this->assertEquals(240000.00, $invResp->json('data.amount_bdt'));
        $this->assertEquals(252000.00, $invResp->json('data.total_bdt')); // 240,000 + 12,000 VAT

        // Verify double-entry ledger entry was posted
        $invoice = Invoice::withoutGlobalScopes()->find($invoiceId);
        $this->assertNotNull($invoice);
        $ledgerCount = LedgerEntry::where('invoice_id', $invoiceId)->count();
        $this->assertGreaterThanOrEqual(2, $ledgerCount);
    }

    public function test_corporate_leased_line_95th_percentile_burstable_billing(): void
    {
        // 1. Create Circuit with committed 50 Mbps @ 25,000 BDT
        /** @var EnterpriseCircuit $circuit */
        $circuit = EnterpriseCircuit::withoutGlobalScopes()->create([
            'circuit_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'circuit_ref' => 'CKT-BURST-'.strtoupper(Str::random(6)),
            'committed_bandwidth_mbps' => 50,
            'sla_target_availability_pct' => 99.90,
            'monthly_rate_bdt' => 25000.00,
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        $baseTime = Carbon::now()->startOfMonth();

        // 2. Ingest 20 periodic bandwidth samples (simulate 5-min intervals)
        // 18 samples at ~45-55 Mbps, and 2 peak bursts at 85 Mbps and 95 Mbps
        for ($i = 1; $i <= 18; $i++) {
            $this->postJson("/api/v1/enterprise/circuits/{$circuit->circuit_id}/samples", [
                'in_mbps' => 45.0 + ($i % 10),
                'out_mbps' => 40.0 + ($i % 8),
                'sampled_at' => $baseTime->copy()->addMinutes($i * 5)->toIso8601String(),
            ])->assertStatus(201);
        }

        // Two top 5% burst samples (should be discarded by 95th percentile)
        $this->postJson("/api/v1/enterprise/circuits/{$circuit->circuit_id}/samples", [
            'in_mbps' => 85.0,
            'out_mbps' => 70.0,
            'sampled_at' => $baseTime->copy()->addMinutes(100)->toIso8601String(),
        ])->assertStatus(201);

        $this->postJson("/api/v1/enterprise/circuits/{$circuit->circuit_id}/samples", [
            'in_mbps' => 95.0,
            'out_mbps' => 80.0,
            'sampled_at' => $baseTime->copy()->addMinutes(105)->toIso8601String(),
        ])->assertStatus(201);

        // 3. Compute 95th percentile rate
        $calcResp = $this->postJson("/api/v1/enterprise/circuits/{$circuit->circuit_id}/calculate-95th", [
            'start_date' => $baseTime->toIso8601String(),
            'end_date' => $baseTime->copy()->addDays(1)->toIso8601String(),
            'overage_rate_per_mbps' => 200.0,
        ]);
        $calcResp->assertStatus(200);

        $data = $calcResp->json('data');
        $this->assertEquals(20, $data['sample_count']);
        $this->assertEquals(50, $data['committed_mbps']);
        // 95th percentile of 20 samples is the 19th sorted sample (the top 1 sample 95Mbps is discarded)
        $this->assertEquals(85.0, $data['percentile_95th_mbps']);
        $this->assertEquals(35.0, $data['overage_mbps']); // 85 - 50 = 35 Mbps overage
        $this->assertEquals(7000.00, $data['overage_charge_bdt']); // 35 * 200 = 7,000 BDT
        $this->assertEquals(32000.00, $data['total_billable_bdt']); // 25,000 + 7,000 = 32,000 BDT
    }

    public function test_sip_trunk_and_voip_cdr_longest_prefix_rating(): void
    {
        // 1. Register Interconnect SIP Trunk
        $trunkResp = $this->postJson('/api/v1/voip/trunks', [
            'tenant_id' => 1,
            'name' => 'SBC-CARRIER-01',
            'host' => '103.145.110.50',
            'port' => 5060,
            'auth_username' => 'trunk_auth_user',
            'auth_password' => 'TrunkPass99!',
            'max_channels' => 60,
        ]);
        $trunkResp->assertStatus(201);
        $trunkId = $trunkResp->json('data.trunk_id');
        $this->assertNotNull($trunkId);

        // 2. Ingest Call to Bangladesh Mobile (+88017... matches +8801 @ 0.60 BDT/min)
        $callStart = Carbon::now()->subMinutes(5);
        $callAnswer = $callStart->copy()->addSeconds(5);
        $callEnd = $callAnswer->copy()->addSeconds(120); // 120 seconds call

        $cdrResp1 = $this->postJson('/api/v1/voip/cdrs', [
            'tenant_id' => 1,
            'trunk_id' => $trunkId,
            'call_id' => 'SIP-CALL-'.Str::random(12).'@carrier.bd',
            'caller_e164' => '+88029876543',
            'callee_e164' => '+8801711223344',
            'started_at' => $callStart->toIso8601String(),
            'answered_at' => $callAnswer->toIso8601String(),
            'ended_at' => $callEnd->toIso8601String(),
            'disposition' => 'ANSWERED',
        ]);
        $cdrResp1->assertStatus(201);
        $this->assertEquals(120, $cdrResp1->json('data.billable_seconds'));
        $this->assertEquals(0.60, $cdrResp1->json('data.rate_per_minute_bdt'));
        $this->assertEquals(1.2000, $cdrResp1->json('data.cost_bdt')); // 2 minutes @ 0.60 = 1.20 BDT

        // 3. Ingest International Call to USA (+1... matches +1 @ 1.50 BDT/min)
        $callStartUsa = Carbon::now()->subMinutes(10);
        $callAnswerUsa = $callStartUsa->copy()->addSeconds(6);
        $callEndUsa = $callAnswerUsa->copy()->addSeconds(60); // 60 seconds call

        $cdrResp2 = $this->postJson('/api/v1/voip/cdrs', [
            'tenant_id' => 1,
            'trunk_id' => $trunkId,
            'call_id' => 'SIP-CALL-USA-'.Str::random(12).'@carrier.bd',
            'caller_e164' => '+8801711000000',
            'callee_e164' => '+14155552671',
            'started_at' => $callStartUsa->toIso8601String(),
            'answered_at' => $callAnswerUsa->toIso8601String(),
            'ended_at' => $callEndUsa->toIso8601String(),
            'disposition' => 'ANSWERED',
        ]);
        $cdrResp2->assertStatus(201);
        $this->assertEquals(60, $cdrResp2->json('data.billable_seconds'));
        $this->assertEquals(1.50, $cdrResp2->json('data.rate_per_minute_bdt'));
        $this->assertEquals(1.5000, $cdrResp2->json('data.cost_bdt')); // 1 minute @ 1.50 = 1.50 BDT
    }
}
