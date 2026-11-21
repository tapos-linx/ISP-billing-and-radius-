<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

use App\Models\CreditNote;
use App\Models\EnterpriseCircuit;
use App\Models\Invoice;
use App\Models\SlaBreach;
use App\Services\Ledger\DoubleEntryLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * P2-1: Enterprise SLA Monitoring & Automated Penalty Credit Service
 */
class EnterpriseSlaService
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService
    ) {}

    /**
     * Register an enterprise dedicated circuit
     *
     * @param  array<string, mixed>  $data
     */
    public function registerCircuit(int $tenantId, array $data): EnterpriseCircuit
    {
        /** @var EnterpriseCircuit $circuit */
        $circuit = EnterpriseCircuit::withoutGlobalScopes()->create([
            'circuit_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'subscriber_id' => $data['subscriber_id'] ?? null,
            'circuit_ref' => (string) $data['circuit_ref'],
            'committed_bandwidth_mbps' => (int) $data['committed_bandwidth_mbps'],
            'sla_target_availability_pct' => (float) ($data['sla_target_availability_pct'] ?? 99.90),
            'max_latency_ms' => (int) ($data['max_latency_ms'] ?? 20),
            'max_jitter_ms' => (int) ($data['max_jitter_ms'] ?? 5),
            'max_packet_loss_pct' => (float) ($data['max_packet_loss_pct'] ?? 0.10),
            'monthly_rate_bdt' => (float) $data['monthly_rate_bdt'],
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        return $circuit;
    }

    /**
     * Perform monthly circuit availability audit and calculate automated penalty credits
     */
    public function auditMonthlySla(string $circuitId, string $billingMonth, int $downtimeMinutes): SlaBreach
    {
        /** @var EnterpriseCircuit $circuit */
        $circuit = EnterpriseCircuit::withoutGlobalScopes()->findOrFail($circuitId);

        // Total minutes in standard 30-day billing month: 30 * 1440 = 43,200
        $totalMinutes = 43200;
        $uptimeMinutes = max(0, $totalMinutes - $downtimeMinutes);
        $actualAvailability = round(($uptimeMinutes / $totalMinutes) * 100.0, 2);

        $penaltyCreditBdt = 0.0;
        $creditNoteId = null;
        $status = 'pending';

        // Check breach against contractual SLA threshold
        if ($actualAvailability < $circuit->sla_target_availability_pct) {
            $diff = $circuit->sla_target_availability_pct - $actualAvailability;

            // Tiered penalty scale
            $penaltyPct = match (true) {
                $diff >= 2.0 => 30.0, // Major outage (>2% under target) -> 30% credit
                $diff >= 1.0 => 20.0, // Outage (>1% under target) -> 20% credit
                $diff >= 0.5 => 10.0, // Moderate breach -> 10% credit
                default => 5.0,       // Minor breach -> 5% credit
            };

            $penaltyCreditBdt = round($circuit->monthly_rate_bdt * ($penaltyPct / 100.0), 2);

            // Locate or initialize invoice for the credit note
            $latestInvoice = Invoice::withoutGlobalScopes()
                ->where('subscriber_id', $circuit->subscriber_id)
                ->latest('due_date')
                ->first();

            $invoiceId = $latestInvoice ? (string) $latestInvoice->invoice_id : (string) Str::uuid();

            if (! $latestInvoice) {
                Invoice::withoutGlobalScopes()->create([
                    'invoice_id' => $invoiceId,
                    'tenant_id' => $circuit->tenant_id,
                    'subscriber_id' => $circuit->subscriber_id,
                    'amount_bdt' => $circuit->monthly_rate_bdt,
                    'subtotal_bdt' => $circuit->monthly_rate_bdt,
                    'vat_rate_percent' => 5.0,
                    'vat_amount_bdt' => round($circuit->monthly_rate_bdt * 0.05, 2),
                    'total_bdt' => round($circuit->monthly_rate_bdt * 1.05, 2),
                    'status' => 'issued',
                    'due_date' => Carbon::now()->addDays(15),
                ]);
            }

            // Generate Credit Note for subscriber
            /** @var CreditNote $cn */
            $cn = CreditNote::withoutGlobalScopes()->create([
                'credit_note_number' => 'CN-SLA-'.Carbon::now()->format('ymd').'-'.strtoupper(Str::random(4)),
                'tenant_id' => $circuit->tenant_id,
                'invoice_id' => $invoiceId,
                'subscriber_id' => (string) $circuit->subscriber_id,
                'subtotal_bdt' => $penaltyCreditBdt,
                'vat_bdt' => 0.0,
                'total_bdt' => $penaltyCreditBdt,
                'reason' => "SLA Breach Penalty: {$actualAvailability}% achieved vs {$circuit->sla_target_availability_pct}% SLA for {$billingMonth}",
                'status' => 'applied',
                'created_at' => Carbon::now(),
            ]);

            $creditNoteId = (int) $cn->id;

            // Post double-entry journal: DR Revenue (4000) / CR Accounts Receivable (1100)
            $this->ledgerService->recordJournalEntry(
                tenantId: $circuit->tenant_id,
                referenceType: 'sla_breach_penalty',
                referenceId: $circuit->circuit_id,
                debitAccountCode: '4000',
                creditAccountCode: '1100',
                amountBdt: $penaltyCreditBdt,
                description: "Automated SLA Penalty Credit for Circuit {$circuit->circuit_ref} ({$billingMonth})",
                subscriberId: $circuit->subscriber_id ? (string) $circuit->subscriber_id : null
            );

            $status = 'credited';
        }

        /** @var SlaBreach $breach */
        $breach = SlaBreach::withoutGlobalScopes()->create([
            'breach_id' => (string) Str::uuid(),
            'circuit_id' => $circuit->circuit_id,
            'tenant_id' => $circuit->tenant_id,
            'billing_month' => $billingMonth,
            'actual_availability_pct' => $actualAvailability,
            'downtime_minutes' => $downtimeMinutes,
            'penalty_credit_bdt' => $penaltyCreditBdt,
            'credit_note_id' => $creditNoteId,
            'status' => $status,
            'created_at' => Carbon::now(),
        ]);

        return $breach;
    }
}
