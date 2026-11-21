<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HotspotProfile;
use App\Models\HotspotVoucher;
use App\Models\Promotion;
use App\Models\Tariff;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 (P1 Batch 3): Commercial Onboarding, CRM & Reseller Hierarchy Feature Test
 */
class CommercialAndResellerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_lead_sales_pipeline_feasibility_quote_and_conversion(): void
    {
        // 1. Create Lead
        $leadResp = $this->postJson('/api/v1/crm/leads', [
            'tenant_id' => 1,
            'name' => 'Tanvir Ahmed',
            'phone' => '+8801811223344',
            'email' => 'tanvir@prospect.bd',
            'address' => 'Road 11, Block D, Banani, Dhaka',
            'notes' => 'Requested 100Mbps dedicated fiber line',
        ]);
        $leadResp->assertStatus(201);
        $leadId = $leadResp->json('data.lead_id');
        $this->assertNotNull($leadId);
        $this->assertEquals('new', $leadResp->json('data.status'));

        // 2. Perform GIS Feasibility Check (Banani coordinates close to central POP)
        $feasResp = $this->postJson("/api/v1/crm/leads/{$leadId}/feasibility", [
            'lat' => 23.7930,
            'lng' => 90.4085,
        ]);
        $feasResp->assertStatus(200);
        $this->assertTrue($feasResp->json('data.is_feasible'));
        $this->assertGreaterThan(0, $feasResp->json('data.max_bandwidth_mbps'));
        $this->assertGreaterThan(0, $feasResp->json('data.distance_meters'));

        // 3. Generate Commercial Quote
        $quoteResp = $this->postJson("/api/v1/crm/leads/{$leadId}/quote", [
            'installation_charge_bdt' => 1200.0,
            'monthly_recurring_bdt' => 1500.0,
        ]);
        $quoteResp->assertStatus(201);
        $this->assertEquals('sent', $quoteResp->json('data.status'));
        $this->assertEquals(1200.0, $quoteResp->json('data.installation_charge_bdt'));

        // 4. Convert Lead to Active Subscriber
        $subResp = $this->postJson("/api/v1/crm/leads/{$leadId}/convert", [
            'username' => 'tanvir_fiber_'.Str::lower(Str::random(5)),
            'password' => 'FiberPass2026!',
            'tariff_id' => 1,
        ]);
        $subResp->assertStatus(200);
        $this->assertEquals('active', $subResp->json('data.status'));
        $this->assertNotNull($subResp->json('data.subscriber_id'));
    }

    public function test_digital_ekyc_ecaf_submission_and_btrc_verification(): void
    {
        // 1. Submit eCAF with 10-digit Smart NID
        $ecafResp = $this->postJson('/api/v1/compliance/ecaf', [
            'tenant_id' => 1,
            'nid_number' => '1990269250',
            'dob' => '1990-05-15',
            'full_name_english' => 'Rafiqul Islam',
            'full_name_bangla' => 'রফিকুল ইসলাম',
            'present_address' => 'House 5, Road 2, Dhanmondi, Dhaka',
            'nid_front_path' => '/storage/ecaf/nid_front_1990269250.jpg',
            'live_selfie_path' => '/storage/ecaf/selfie_1990269250.jpg',
            'customer_signature_path' => '/storage/ecaf/signature_1990269250.png',
        ]);
        $ecafResp->assertStatus(201);
        $ecafId = $ecafResp->json('data.ecaf_id');
        $this->assertNotNull($ecafId);
        $this->assertEquals('pending', $ecafResp->json('data.verification_status'));

        // 2. Verify eKYC against Election Commission mock
        $verifyResp = $this->postJson("/api/v1/compliance/ecaf/{$ecafId}/verify");
        $verifyResp->assertStatus(200);
        $this->assertTrue($verifyResp->json('verified'));
        $this->assertEquals('verified', $verifyResp->json('status'));

        // 3. Export BTRC Audit record
        $exportResp = $this->getJson("/api/v1/compliance/ecaf/{$ecafId}/export");
        $exportResp->assertStatus(200);
        $this->assertEquals('BTRC Bangladesh', $exportResp->json('data.regulatory_body'));
        $this->assertEquals('1990269250', $exportResp->json('data.nid_number'));
        $this->assertEquals('verified', $exportResp->json('data.verification_status'));
    }

    public function test_reseller_prepaid_wallet_topup_and_activation_debit_with_commission(): void
    {
        $resellerId = 9921;

        // 1. Get or Create Reseller Wallet (default 15% commission)
        $walletResp = $this->getJson("/api/v1/reseller/wallets/{$resellerId}?tenant_id=1");
        $walletResp->assertStatus(200);
        $walletId = $walletResp->json('data.wallet_id');
        $this->assertNotNull($walletId);
        $this->assertEquals(15.0, $walletResp->json('data.commission_rate_pct'));

        // 2. Top-up Reseller Wallet with 10,000 BDT
        $topupResp = $this->postJson("/api/v1/reseller/wallets/{$walletId}/topup", [
            'amount_bdt' => 10000.0,
            'reference_id' => 'BANK-DEP-778899',
            'description' => 'City Bank Cash Deposit',
        ]);
        $topupResp->assertStatus(200);
        $this->assertEquals('topup', $topupResp->json('data.type'));
        $this->assertGreaterThanOrEqual(10000.0, $topupResp->json('data.balance_after_bdt'));

        // 3. Debit for Subscriber Activation: Plan 1,000 BDT -> 15% commission (150 BDT) -> Net debit 850 BDT
        $subId = (string) Str::uuid();
        $debitResp = $this->postJson("/api/v1/reseller/wallets/{$walletId}/activate", [
            'plan_amount_bdt' => 1000.0,
            'subscriber_id' => $subId,
        ]);
        $debitResp->assertStatus(200);
        $this->assertEquals('activation_debit', $debitResp->json('data.type'));
        $this->assertEquals(850.0, $debitResp->json('data.amount_bdt'));

        // 4. Query Reseller Transaction Ledger
        $txResp = $this->getJson("/api/v1/reseller/wallets/{$walletId}/transactions");
        $txResp->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($txResp->json('data')));
    }

    public function test_online_self_signup_and_promotional_coupon(): void
    {
        // 1. Seed a 20% discount coupon
        $promoCode = 'WELCOME20_'.strtoupper(Str::random(4));
        Promotion::withoutGlobalScopes()->create([
            'promo_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'code' => $promoCode,
            'discount_type' => 'percentage',
            'discount_value' => 20.0,
            'min_plan_amount_bdt' => 500.0,
            'valid_from' => Carbon::now()->subDay(),
            'valid_until' => Carbon::now()->addMonth(),
            'usage_limit' => 50,
            'used_count' => 0,
            'active' => true,
            'created_at' => Carbon::now(),
        ]);

        // 2. Validate Promo Code
        $checkPromoResp = $this->postJson('/api/v1/self-care/promotions/apply', [
            'tenant_id' => 1,
            'promo_code' => $promoCode,
            'plan_amount_bdt' => 1000.0,
        ]);
        $checkPromoResp->assertStatus(200);
        $this->assertTrue($checkPromoResp->json('data.valid'));
        $this->assertEquals(200.0, $checkPromoResp->json('data.discount_amount_bdt'));
        $this->assertEquals(800.0, $checkPromoResp->json('data.payable_amount_bdt'));

        // 3. Complete Self-Care Registration with Promo Code
        $tariff = Tariff::withoutGlobalScopes()->first();
        $tariffId = $tariff ? (int) $tariff->tariff_id : 1;

        $signupResp = $this->postJson('/api/v1/self-care/signup', [
            'tenant_id' => 1,
            'username' => 'self_user_'.Str::lower(Str::random(6)),
            'password' => 'P@ssw0rd2026',
            'tariff_id' => $tariffId,
            'promo_code' => $promoCode,
        ]);
        $signupResp->assertStatus(201);
        $this->assertEquals('success', $signupResp->json('status'));
        $this->assertNotNull($signupResp->json('subscriber_id'));
        $this->assertNotNull($signupResp->json('invoice_id'));
    }

    public function test_dynamic_captive_portal_and_wispr_and_voucher_authentication(): void
    {
        // 1. Render Captive Portal HTML
        $portalResp = $this->get('/api/v1/hotspot/portal?brand_name=MegaNet%20Broadband');
        $portalResp->assertStatus(200);
        $portalResp->assertSee('MegaNet Broadband Wi-Fi');
        $portalResp->assertSee('Voucher Code');

        // 2. Generate WISPr 1.0 XML redirection
        $wisprResp = $this->get('/api/v1/hotspot/wispr');
        $wisprResp->assertStatus(200);
        $wisprResp->assertSee('<WISPAccessGatewayParam', false);
        $wisprResp->assertSee('<AccessProcedure>1.0</AccessProcedure>', false);

        // 3. Create Hotspot Voucher for testing authentication
        $profile = HotspotProfile::withoutGlobalScopes()->first();
        $profileId = $profile ? (string) $profile->profile_id : (string) Str::uuid();

        $voucherCode = 'WIFI-'.strtoupper(Str::random(6));
        $voucher = HotspotVoucher::withoutGlobalScopes()->create([
            'voucher_id' => (string) Str::uuid(),
            'tenant_id' => 1,
            'profile_id' => $profileId,
            'batch_id' => 'BATCH-'.strtoupper(Str::random(10)),
            'code' => $voucherCode,
            'pin' => '1234',
            'status' => 'created',
            'time_limit_seconds' => 3600,
            'data_limit_bytes' => 1073741824,
            'rate_limit_down_kbps' => 10240,
            'rate_limit_up_kbps' => 5120,
            'price_bdt' => 50.0,
        ]);

        // 4. Authenticate Voucher from Captive Portal
        $authResp = $this->postJson('/api/v1/hotspot/login', [
            'voucher_code' => $voucherCode,
            'pin' => '1234',
            'mac_address' => 'AA:BB:CC:11:22:33',
        ]);
        $authResp->assertStatus(200);
        $this->assertTrue($authResp->json('authorized'));
        $this->assertEquals('active', $authResp->json('status'));
        $this->assertEquals(10240, $authResp->json('rate_limit_down_kbps'));

        // 5. Subsequent login with different MAC address is rejected (Device Mismatch)
        $mismatchResp = $this->postJson('/api/v1/hotspot/login', [
            'voucher_code' => $voucherCode,
            'pin' => '1234',
            'mac_address' => 'FF:EE:DD:44:55:66',
        ]);
        $mismatchResp->assertStatus(401);
        $this->assertFalse($mismatchResp->json('authorized'));
        $this->assertEquals('device_mismatch', $mismatchResp->json('status'));
    }
}
