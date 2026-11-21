<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Subscriber;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0-4, P0-5, P0-6: Billing Lifecycle, Pro-Rata, Credit Notes, Refunds & Dunning Tests
 */
class BillingLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure test tariffs exist
        if (! DB::table('tariffs')->where('tariff_id', 101)->exists()) {
            DB::table('tariffs')->insert([
                'tariff_id' => 101,
                'tenant_id' => 1,
                'name' => 'Plan 10M Basic',
                'rate_limit' => '10M/10M',
                'price_bdt' => 500.00,
                'validity_days' => 30,
            ]);
        }

        if (! DB::table('tariffs')->where('tariff_id', 102)->exists()) {
            DB::table('tariffs')->insert([
                'tariff_id' => 102,
                'tenant_id' => 1,
                'name' => 'Plan 20M Ultra',
                'rate_limit' => '20M/20M',
                'price_bdt' => 1000.00,
                'validity_days' => 30,
            ]);
        }
    }

    public function test_pro_rata_calculation_and_plan_upgrade(): void
    {
        $subId = (string) Str::uuid();
        $username = 'sub_prorata_'.time();

        $subscriber = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'tariff_id' => 101,
            'username' => $username,
            'bandwidth_profile' => '10M/10M',
            'status' => 'active',
            'expires_at' => Carbon::now()->addDays(15),
            'created_at' => Carbon::now(),
        ]);

        // 1. Calculate
        $calcResponse = $this->postJson('/api/v1/billing/plan-change/calculate', [
            'subscriber_id' => $subId,
            'new_tariff_id' => 102,
        ]);

        $calcResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.is_upgrade', true);

        // 2. Apply
        $applyResponse = $this->postJson('/api/v1/billing/plan-change/apply', [
            'subscriber_id' => $subId,
            'new_tariff_id' => 102,
        ]);

        $applyResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.new_rate_limit', '20M/20M');

        $subscriber->refresh();
        $this->assertEquals(102, $subscriber->tariff_id);
        $this->assertEquals('20M/20M', $subscriber->bandwidth_profile);
    }

    public function test_issue_credit_note_and_void_invoice(): void
    {
        $subId = (string) Str::uuid();
        $invId = (string) Str::uuid();

        Invoice::withoutGlobalScopes()->create([
            'invoice_id' => $invId,
            'tenant_id' => 1,
            'subscriber_id' => $subId,
            'amount_bdt' => 500.00,
            'subtotal_bdt' => 476.19,
            'vat_rate_percent' => 5.00,
            'vat_amount_bdt' => 23.81,
            'total_bdt' => 500.00,
            'status' => 'unpaid',
            'due_date' => Carbon::now()->addDays(5),
            'mushak_6_3_number' => 'MUSHAK-TEST-01',
            'created_at' => Carbon::now(),
        ]);

        // Issue Credit Note
        $cnResponse = $this->postJson("/api/v1/billing/invoices/{$invId}/credit-note", [
            'amount' => 100.00,
            'reason' => 'Service disruption courtesy credit',
        ]);

        $cnResponse->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.total_bdt', '100.00');

        // Void invoice
        $voidResponse = $this->postJson("/api/v1/billing/invoices/{$invId}/void", [
            'reason' => 'Customer relocated',
        ]);

        $voidResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'void');
    }

    public function test_process_refund_against_payment(): void
    {
        $subId = (string) Str::uuid();
        $invId = (string) Str::uuid();
        $payId = (string) Str::uuid();

        $payment = Payment::withoutGlobalScopes()->create([
            'payment_id' => $payId,
            'tenant_id' => 1,
            'subscriber_id' => $subId,
            'invoice_id' => $invId,
            'amount_bdt' => 1000.00,
            'gateway' => 'bkash',
            'trx_id' => 'TRX_REFUND_TEST_'.time(),
            'status' => 'completed',
            'paid_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        $refundResponse = $this->postJson("/api/v1/billing/payments/{$payId}/refund", [
            'amount' => 250.00,
            'reason' => 'Partial billing dispute refund',
            'method' => 'bkash',
        ]);

        $refundResponse->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.amount_bdt', '250.00')
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_vacation_mode_activation(): void
    {
        $subId = (string) Str::uuid();
        $username = 'sub_vacation_'.time();

        $subscriber = Subscriber::withoutGlobalScopes()->create([
            'subscriber_id' => $subId,
            'tenant_id' => 1,
            'tariff_id' => 101,
            'username' => $username,
            'bandwidth_profile' => '10M/10M',
            'status' => 'active',
            'expires_at' => Carbon::now()->addDays(20),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson("/api/v1/billing/subscribers/{$subId}/vacation-mode", [
            'duration_days' => 30,
            'reason' => 'Travelling abroad',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.duration_days', 30);

        $subscriber->refresh();
        $this->assertEquals('suspended', $subscriber->status);
        $this->assertNotNull($subscriber->vacation_until);
    }
}
