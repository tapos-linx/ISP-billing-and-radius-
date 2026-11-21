<?php

declare(strict_types=1);

namespace App\Services\Wholesale;

use App\Models\Invoice;
use App\Models\L2tpTunnel;
use App\Models\Subscriber;
use App\Models\WholesaleCarrier;
use App\Services\Ledger\DoubleEntryLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * P2-5: Carrier Wholesale Interconnect & L2TP LAC/LNS Tunnel Invoicing Service
 */
class WholesaleCarrierService
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService
    ) {}

    /**
     * Register a wholesale interconnect carrier partner
     *
     * @param  array<string, mixed>  $data
     */
    public function registerCarrier(int $tenantId, array $data): WholesaleCarrier
    {
        /** @var WholesaleCarrier $carrier */
        $carrier = WholesaleCarrier::withoutGlobalScopes()->create([
            'carrier_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'carrier_name' => (string) $data['carrier_name'],
            'carrier_code' => strtoupper((string) $data['carrier_code']),
            'account_number' => (string) $data['account_number'],
            'rate_per_mbps_bdt' => isset($data['rate_per_mbps_bdt']) ? (float) $data['rate_per_mbps_bdt'] : 150.00,
            'status' => 'active',
            'created_at' => Carbon::now(),
        ]);

        return $carrier;
    }

    /**
     * Provision an L2TP LAC/LNS tunnel for wholesale traffic backhaul
     *
     * @param  array<string, mixed>  $data
     */
    public function provisionTunnel(string $carrierId, array $data): L2tpTunnel
    {
        /** @var WholesaleCarrier $carrier */
        $carrier = WholesaleCarrier::withoutGlobalScopes()->findOrFail($carrierId);

        /** @var L2tpTunnel $tunnel */
        $tunnel = L2tpTunnel::withoutGlobalScopes()->create([
            'tunnel_id' => (string) Str::uuid(),
            'carrier_id' => $carrier->carrier_id,
            'tenant_id' => $carrier->tenant_id,
            'tunnel_name' => (string) $data['tunnel_name'],
            'local_endpoint_ip' => (string) $data['local_endpoint_ip'],
            'remote_endpoint_ip' => (string) $data['remote_endpoint_ip'],
            'shared_secret' => (string) $data['shared_secret'],
            'committed_mbps' => isset($data['committed_mbps']) ? (int) $data['committed_mbps'] : 1000,
            'active_sessions' => 0,
            'status' => 'up',
            'created_at' => Carbon::now(),
        ]);

        return $tunnel;
    }

    /**
     * Generate monthly wholesale interconnect trunk invoice based on committed or burst Mbps
     */
    public function generateWholesaleInvoice(string $carrierId, int $billedMbps): Invoice
    {
        /** @var WholesaleCarrier $carrier */
        $carrier = WholesaleCarrier::withoutGlobalScopes()->findOrFail($carrierId);

        $subtotal = round($billedMbps * $carrier->rate_per_mbps_bdt, 2);
        $vat = round($subtotal * 0.05, 2); // 5% Telecom VAT
        $total = round($subtotal + $vat, 2);

        // Ensure carrier has a linked subscriber account for billing
        $carrierUsername = 'carrier_'.strtolower($carrier->carrier_code);
        /** @var Subscriber|null $carrierSub */
        $carrierSub = Subscriber::withoutGlobalScopes()
            ->where('username', $carrierUsername)
            ->first();

        if ($carrierSub === null) {
            /** @var Subscriber $carrierSub */
            $carrierSub = Subscriber::withoutGlobalScopes()->create([
                'subscriber_id' => (string) Str::uuid(),
                'tenant_id' => $carrier->tenant_id,
                'username' => $carrierUsername,
                'password' => Str::random(16),
                'tariff_id' => 1,
                'status' => 'active',
                'expiry_date' => Carbon::now()->addYears(10),
                'auto_pay_enabled' => false,
                'pro_rata_balance' => 0.0,
            ]);
        }

        /** @var Invoice $invoice */
        $invoice = Invoice::withoutGlobalScopes()->create([
            'invoice_id' => (string) Str::uuid(),
            'tenant_id' => $carrier->tenant_id,
            'subscriber_id' => $carrierSub->subscriber_id,
            'amount_bdt' => $subtotal,
            'subtotal_bdt' => $subtotal,
            'vat_rate_percent' => 5.0,
            'vat_amount_bdt' => $vat,
            'total_bdt' => $total,
            'status' => 'issued',
            'due_date' => Carbon::now()->addDays(15),
        ]);

        // Post to General Ledger
        $this->ledgerService->recordJournalEntry(
            tenantId: $carrier->tenant_id,
            referenceType: 'wholesale_invoice',
            referenceId: $invoice->invoice_id,
            debitAccountCode: '1100', // Accounts Receivable
            creditAccountCode: '4000', // Revenue
            amountBdt: $subtotal,
            description: "Wholesale Trunk Settlement for {$carrier->carrier_name} ({$billedMbps} Mbps @ BDT {$carrier->rate_per_mbps_bdt}/Mbps)",
            subscriberId: (string) $carrierSub->subscriber_id,
            invoiceId: (string) $invoice->invoice_id
        );

        return $invoice;
    }
}
