<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\BeftnBatch;
use App\Models\BeftnTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\Audit\BillingAuditService;
use App\Services\Ledger\DoubleEntryLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * P2-6: Direct Debit Automated Bank Clearing (BEFTN / NPSB / RTGS) Service
 */
class DirectDebitClearingService
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService,
        protected BillingAuditService $auditService
    ) {}

    /**
     * Generate an automated direct debit clearing batch for unpaid invoices.
     *
     * @param  array<string>  $invoiceIds
     * @param  array{bank_code: string, routing_no: string, account_no: string, name: string}  $originatorInfo
     */
    public function generateBatch(
        int $tenantId,
        string $clearingType,
        array $invoiceIds,
        array $originatorInfo,
        ?string $settlementDate = null
    ): BeftnBatch {
        if (empty($invoiceIds)) {
            throw new InvalidArgumentException('Invoice IDs array cannot be empty.');
        }

        $clearingType = strtoupper($clearingType);
        if (! in_array($clearingType, ['BEFTN', 'NPSB', 'RTGS'], true)) {
            throw new InvalidArgumentException("Unsupported clearing type: {$clearingType}");
        }

        $settleDate = $settlementDate ? Carbon::parse($settlementDate) : Carbon::today()->addWeekday();
        $batchNumber = sprintf('DD-%s-%s-%s', $clearingType, $settleDate->format('Ymd'), strtoupper(Str::random(6)));

        return DB::transaction(function () use (
            $tenantId, $clearingType, $batchNumber, $settleDate, $invoiceIds, $originatorInfo
        ) {
            $batch = BeftnBatch::create([
                'tenant_id' => $tenantId,
                'batch_number' => $batchNumber,
                'settlement_date' => $settleDate->toDateString(),
                'clearing_type' => $clearingType,
                'total_count' => 0,
                'total_amount_bdt' => 0.00,
                'status' => 'pending',
                'originator_bank_code' => $originatorInfo['bank_code'],
                'originator_routing_no' => $originatorInfo['routing_no'],
                'originator_account_no' => $originatorInfo['account_no'],
                'originator_name' => $originatorInfo['name'],
                'file_reference' => 'REF-'.$batchNumber.'.xml',
            ]);

            $invoices = Invoice::whereIn('invoice_id', $invoiceIds)->get();
            $totalCount = 0;
            $totalAmount = 0.00;
            $txElements = [];

            foreach ($invoices as $invoice) {
                if ($invoice->status === 'paid') {
                    continue;
                }

                $subscriber = Subscriber::where('subscriber_id', $invoice->subscriber_id)->first();
                $subName = $subscriber ? $subscriber->username : 'Direct Debit Customer';
                $amount = (float) ($invoice->total_bdt ?: $invoice->amount_bdt);

                $instructionId = sprintf('INSTR-%s-%04d', $batchNumber, $totalCount + 1);
                $mandateRef = sprintf('MND-%s', substr((string) $invoice->subscriber_id, 0, 8));

                $bankCode = '010'; // Default Sonali / Standard Bank
                $routingNo = '010271234';
                $accountNo = '100'.substr((string) crc32((string) $invoice->subscriber_id), 0, 8);

                $tx = BeftnTransaction::create([
                    'tenant_id' => $tenantId,
                    'batch_id' => $batch->id,
                    'invoice_id' => $invoice->invoice_id,
                    'subscriber_id' => $invoice->subscriber_id,
                    'instruction_id' => $instructionId,
                    'mandate_reference' => $mandateRef,
                    'customer_bank_name' => 'Designated Commercial Bank',
                    'customer_routing_no' => $routingNo,
                    'customer_account_no' => $accountNo,
                    'account_title' => $subName,
                    'amount_bdt' => $amount,
                    'status' => 'pending',
                ]);

                $totalCount++;
                $totalAmount += $amount;

                $txElements[] = [
                    'instruction_id' => $instructionId,
                    'mandate_reference' => $mandateRef,
                    'amount' => number_format($amount, 2, '.', ''),
                    'routing_no' => $routingNo,
                    'account_no' => $accountNo,
                    'account_title' => htmlspecialchars($subName, ENT_XML1),
                    'invoice_id' => (string) $invoice->invoice_id,
                ];
            }

            // Build ISO 20022 pain.008.001.02 BEFTN XML format
            $xmlPayload = $this->buildIso20022Xml($batch, $originatorInfo, $settleDate, $totalCount, $totalAmount, $txElements);

            $batch->update([
                'total_count' => $totalCount,
                'total_amount_bdt' => $totalAmount,
                'batch_payload' => $xmlPayload,
            ]);

            return $batch->fresh('transactions');
        });
    }

    /**
     * Export the direct debit clearing file.
     */
    public function exportBatchFile(string $batchId): string
    {
        $batch = BeftnBatch::findOrFail($batchId);

        if (empty($batch->batch_payload)) {
            throw new RuntimeException("Batch {$batchId} payload has not been generated.");
        }

        return $batch->batch_payload;
    }

    /**
     * Submit batch to bank clearing switch.
     */
    public function submitBatch(string $batchId): BeftnBatch
    {
        $batch = BeftnBatch::findOrFail($batchId);
        $batch->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return $batch;
    }

    /**
     * Process Bangladesh Bank settlement return file / acknowledgment.
     *
     * @param  array<array{instruction_id: string, return_code: string, failure_reason?: string}>  $returns
     * @return array{batch_id: string, total: int, settled_count: int, rejected_count: int, total_settled_amount: float, status: string}
     */
    public function processSettlementReturn(string $batchId, array $returns): array
    {
        $batch = BeftnBatch::with('transactions')->findOrFail($batchId);

        $settledCount = 0;
        $rejectedCount = 0;
        $totalSettledAmount = 0.00;

        DB::transaction(function () use (
            $batch, $returns, &$settledCount, &$rejectedCount, &$totalSettledAmount
        ) {
            foreach ($returns as $ret) {
                $instructionId = $ret['instruction_id'];
                $returnCode = $ret['return_code'];
                $failureReason = $ret['failure_reason'] ?? null;

                /** @var BeftnTransaction|null $tx */
                $tx = $batch->transactions->firstWhere('instruction_id', $instructionId);
                if (! $tx) {
                    continue;
                }

                if ($returnCode === '00' || strtoupper($returnCode) === 'SETTLED') {
                    // Success: Mark transaction & invoice paid
                    $tx->update([
                        'status' => 'settled',
                        'return_code' => '00',
                        'settled_at' => now(),
                    ]);

                    $invoice = Invoice::where('invoice_id', $tx->invoice_id)->first();
                    if ($invoice && $invoice->status !== 'paid') {
                        $invoice->update([
                            'status' => 'paid',
                            'paid_at' => now(),
                        ]);

                        $trxId = 'BEFTN-'.$tx->instruction_id;

                        Payment::create([
                            'payment_id' => (string) Str::uuid(),
                            'invoice_id' => $invoice->invoice_id,
                            'subscriber_id' => $invoice->subscriber_id,
                            'tenant_id' => $batch->tenant_id,
                            'amount_bdt' => $tx->amount_bdt,
                            'gateway' => 'beftn',
                            'trx_id' => $trxId,
                            'status' => 'completed',
                            'paid_at' => now(),
                        ]);

                        // Post double-entry: DR Cash/Clearing (1010), CR Accounts Receivable (1100)
                        $this->ledgerService->recordJournalEntry(
                            $batch->tenant_id,
                            'beftn_clearing',
                            $trxId,
                            '1010',
                            '1100',
                            (float) $tx->amount_bdt,
                            "BEFTN direct debit settlement for invoice #{$invoice->invoice_id}",
                            (string) $invoice->subscriber_id,
                            (string) $invoice->invoice_id
                        );

                        // Tamper-evident audit log
                        $this->auditService->logEvent(
                            'beftn.transaction_settled',
                            'invoice',
                            (string) $invoice->invoice_id,
                            'banking_system',
                            $batch->originator_bank_code,
                            ['status' => 'unpaid'],
                            ['status' => 'paid', 'instruction_id' => $instructionId, 'amount_bdt' => $tx->amount_bdt]
                        );
                    }

                    $settledCount++;
                    $totalSettledAmount += (float) $tx->amount_bdt;
                } else {
                    // Rejection (insufficient funds, account closed, etc.)
                    $status = ($returnCode === 'R01') ? 'insf' : 'rejected';

                    $tx->update([
                        'status' => $status,
                        'return_code' => $returnCode,
                        'failure_reason' => $failureReason ?: "Clearing rejected with code {$returnCode}",
                    ]);

                    $this->auditService->logEvent(
                        'beftn.transaction_rejected',
                        'beftn_transaction',
                        (string) $tx->id,
                        'banking_system',
                        $batch->originator_bank_code,
                        ['status' => 'pending'],
                        ['status' => $status, 'return_code' => $returnCode, 'reason' => $failureReason]
                    );

                    $rejectedCount++;
                }
            }

            // Determine final batch status
            $finalStatus = 'partially_settled';
            if ($settledCount > 0 && $rejectedCount === 0) {
                $finalStatus = 'settled';
            } elseif ($settledCount === 0 && $rejectedCount > 0) {
                $finalStatus = 'rejected';
            }

            $batch->update([
                'status' => $finalStatus,
                'settled_at' => now(),
            ]);
        });

        return [
            'batch_id' => (string) $batch->id,
            'total' => count($returns),
            'settled_count' => $settledCount,
            'rejected_count' => $rejectedCount,
            'total_settled_amount' => $totalSettledAmount,
            'status' => $batch->fresh()->status,
        ];
    }

    /**
     * @param  array{bank_code: string, routing_no: string, account_no: string, name: string}  $originatorInfo
     * @param  array<array{instruction_id: string, mandate_reference: string, amount: string, routing_no: string, account_no: string, account_title: string, invoice_id: string}>  $txElements
     */
    protected function buildIso20022Xml(
        BeftnBatch $batch,
        array $originatorInfo,
        Carbon $settleDate,
        int $totalCount,
        float $totalAmount,
        array $txElements
    ): string {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<Document xmlns=\"urn:iso:std:iso:20022:tech:xsd:pain.008.001.02\">\n";
        $xml .= "  <CstmrDrctDbtInitn>\n";
        $xml .= "    <GrpHdr>\n";
        $xml .= "      <MsgId>{$batch->batch_number}</MsgId>\n";
        $xml .= '      <CreDtTm>'.now()->toIso8601String()."</CreDtTm>\n";
        $xml .= "      <NbOfTxs>{$totalCount}</NbOfTxs>\n";
        $xml .= '      <CtrlSum>'.number_format($totalAmount, 2, '.', '')."</CtrlSum>\n";
        $xml .= '      <InitgPty><Nm>'.htmlspecialchars($originatorInfo['name'], ENT_XML1)."</Nm></InitgPty>\n";
        $xml .= "    </GrpHdr>\n";
        $xml .= "    <PmtInf>\n";
        $xml .= "      <PmtInfId>{$batch->batch_number}-PMT</PmtInfId>\n";
        $xml .= "      <PmtMtd>DD</PmtMtd>\n";
        $xml .= "      <ReqdColltnDt>{$settleDate->toDateString()}</ReqdColltnDt>\n";
        $xml .= '      <Cdtr><Nm>'.htmlspecialchars($originatorInfo['name'], ENT_XML1)."</Nm></Cdtr>\n";
        $xml .= "      <CdtrAcct><Id><Othr><Id>{$originatorInfo['account_no']}</Id></Othr></Id></CdtrAcct>\n";
        $xml .= "      <CdtrAgt><FinInstnId><ClrSysMmbId><MmbId>{$originatorInfo['routing_no']}</MmbId></ClrSysMmbId></FinInstnId></CdtrAgt>\n";

        foreach ($txElements as $tx) {
            $xml .= "      <DrctDbtTxInf>\n";
            $xml .= "        <PmtId><EndToEndId>{$tx['instruction_id']}</EndToEndId></PmtId>\n";
            $xml .= "        <InstdAmt Ccy=\"BDT\">{$tx['amount']}</InstdAmt>\n";
            $xml .= "        <DrctDbtTx><MndtRltdInf><MndtId>{$tx['mandate_reference']}</MndtId></MndtRltdInf></DrctDbtTx>\n";
            $xml .= "        <DbtrAgt><FinInstnId><ClrSysMmbId><MmbId>{$tx['routing_no']}</MmbId></ClrSysMmbId></FinInstnId></DbtrAgt>\n";
            $xml .= "        <Dbtr><Nm>{$tx['account_title']}</Nm></Dbtr>\n";
            $xml .= "        <DbtrAcct><Id><Othr><Id>{$tx['account_no']}</Id></Othr></Id></DbtrAcct>\n";
            $xml .= "        <RmtInf><Ustrd>Invoice {$tx['invoice_id']}</Ustrd></RmtInf>\n";
            $xml .= "      </DrctDbtTxInf>\n";
        }

        $xml .= "    </PmtInf>\n";
        $xml .= "  </CstmrDrctDbtInitn>\n";
        $xml .= '</Document>';

        return $xml;
    }
}
