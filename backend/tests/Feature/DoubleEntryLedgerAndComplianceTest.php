<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Audit\BillingAuditService;
use App\Services\Compliance\ComplianceReportingService;
use App\Services\Ledger\DoubleEntryLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoubleEntryLedgerAndComplianceTest extends TestCase
{
    private DoubleEntryLedgerService $ledgerService;
    private BillingAuditService $auditService;
    private ComplianceReportingService $complianceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = app(DoubleEntryLedgerService::class);
        $this->auditService = app(BillingAuditService::class);
        $this->complianceService = app(ComplianceReportingService::class);
    }

    public function test_invoice_issuance_creates_balanced_ledger_entries(): void
    {
        $subId = (string) Str::uuid();
        $invId = (string) Str::uuid();

        $invoice = Invoice::create([
            'invoice_id'        => $invId,
            'subscriber_id'     => $subId,
            'tenant_id'         => 1,
            'amount_bdt'        => 1050.00,
            'subtotal_bdt'      => 1000.00,
            'vat_rate_percent'  => 5.00,
            'vat_amount_bdt'    => 50.00,
            'total_bdt'         => 1050.00,
            'status'            => 'unpaid',
            'due_date'          => now()->addDays(7),
            'mushak_6_3_number' => 'MUSHAK-TEST-001',
        ]);

        $journalId = $this->ledgerService->recordInvoiceIssuance($invoice);
        $this->assertNotEmpty($journalId);

        $entries = DB::table('ledger_entries')->where('journal_entry_id', $journalId)->get();
        $this->assertCount(3, $entries);

        $debits = $entries->sum('debit_bdt');
        $credits = $entries->sum('credit_bdt');
        $this->assertEquals(1050.00, $debits);
        $this->assertEquals(1050.00, $credits);

        // Cleanup
        DB::table('ledger_entries')->where('journal_entry_id', $journalId)->delete();
        $invoice->delete();
    }

    public function test_payment_settlement_creates_balanced_ledger_entries(): void
    {
        $subId = (string) Str::uuid();
        $invId = (string) Str::uuid();
        $payId = (string) Str::uuid();

        $invoice = Invoice::create([
            'invoice_id'        => $invId,
            'subscriber_id'     => $subId,
            'tenant_id'         => 1,
            'amount_bdt'        => 1050.00,
            'subtotal_bdt'      => 1000.00,
            'vat_rate_percent'  => 5.00,
            'vat_amount_bdt'    => 50.00,
            'total_bdt'         => 1050.00,
            'status'            => 'unpaid',
            'due_date'          => now()->addDays(7),
            'mushak_6_3_number' => 'MUSHAK-TEST-002',
        ]);

        $payment = Payment::create([
            'payment_id'    => $payId,
            'invoice_id'    => $invId,
            'subscriber_id' => $subId,
            'tenant_id'     => 1,
            'amount_bdt'    => 1050.00,
            'gateway'       => 'bkash',
            'trx_id'        => 'TRX_TEST_' . bin2hex(random_bytes(4)),
            'status'        => 'completed',
            'paid_at'       => now(),
        ]);

        $journalId = $this->ledgerService->recordPaymentSettlement($invoice, $payment);
        $this->assertNotEmpty($journalId);

        $entries = DB::table('ledger_entries')->where('journal_entry_id', $journalId)->get();
        $this->assertCount(2, $entries);

        $debits = $entries->sum('debit_bdt');
        $credits = $entries->sum('credit_bdt');
        $this->assertEquals(1050.00, $debits);
        $this->assertEquals(1050.00, $credits);

        // Cleanup
        DB::table('ledger_entries')->where('journal_entry_id', $journalId)->delete();
        $payment->delete();
        $invoice->delete();
    }

    public function test_reconciliation_verifies_balanced_general_ledger(): void
    {
        $report = $this->ledgerService->reconcileLedger();

        $this->assertIsArray($report);
        $this->assertTrue($report['is_balanced']);
        $this->assertEquals(0, $report['imbalanced_count']);
        $this->assertEquals($report['total_debit_bdt'], $report['total_credit_bdt']);
    }

    public function test_append_only_audit_log_prevents_updates_and_deletions(): void
    {
        $log = $this->auditService->logEvent(
            eventType: 'invoice_settled',
            entityType: 'invoice',
            entityId: (string) Str::uuid(),
            actorType: 'system',
            actorId: 'PHPUnit',
            prevState: ['status' => 'unpaid'],
            newState: ['status' => 'paid'],
            ipAddress: '127.0.0.1'
        );

        $this->assertNotEmpty($log->hash_signature);
        $this->assertNotEmpty($log->event_id);

        // Attempting an UPDATE must fail with SQL trigger exception
        $updateFailed = false;
        try {
            DB::table('billing_audit_logs')->where('id', $log->id)->update([
                'actor_id' => 'malicious_actor',
            ]);
        } catch (\Throwable $e) {
            $updateFailed = true;
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertTrue($updateFailed, "Expected immutable audit log update to be blocked by database trigger.");

        // Attempting a DELETE must fail with SQL trigger exception
        $deleteFailed = false;
        try {
            DB::table('billing_audit_logs')->where('id', $log->id)->delete();
        } catch (\Throwable $e) {
            $deleteFailed = true;
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->assertTrue($deleteFailed, "Expected immutable audit log deletion to be blocked by database trigger.");
    }

    public function test_compliance_service_generates_vat_and_btrc_reports(): void
    {
        $month = date('Y-m');

        // NBR 5% VAT Mushak-6.3
        $vatReport = $this->complianceService->generateMonthlyVatMushakReport($month);
        $this->assertEquals($month, $vatReport['year_month']);
        $this->assertEquals('5.00%', $vatReport['vat_rate']);
        $this->assertArrayHasKey('gross_revenue_bdt', $vatReport);

        // BTRC Regulatory Compliance Return
        $btrcReport = $this->complianceService->generateBtrcRegulatoryReport($month);
        $this->assertEquals($month, $btrcReport['report_period']);
        $this->assertEquals('COMPLIANT', $btrcReport['compliance_status']);
        $this->assertArrayHasKey('total_active_subscribers', $btrcReport);
    }
}
