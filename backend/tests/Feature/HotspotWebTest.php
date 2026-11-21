<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HotspotProfile;
use App\Models\HotspotVoucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotspotWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_hotspot_captive_portal_page_loads_successfully(): void
    {
        HotspotProfile::create([
            'tenant_id' => 1,
            'name' => '1 Hour Standard Pass',
            'validity_seconds' => 3600,
            'data_limit_bytes' => 1073741824,
            'rate_limit_down_kbps' => 10240,
            'rate_limit_up_kbps' => 10240,
            'price_bdt' => 25.00,
            'simultaneous_use' => 1,
            'created_at' => now(),
        ]);

        $response = $this->get('/hotspot/login');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Hotspot/CaptivePortal')
            ->has('profiles', 1)
            ->where('hotspotName', 'Carrier Gigabit Public Wi-Fi')
        );
    }

    public function test_hotspot_voucher_print_batch_loads_successfully(): void
    {
        $profile = HotspotProfile::create([
            'tenant_id' => 1,
            'name' => '24 Hours Pass',
            'validity_seconds' => 86400,
            'data_limit_bytes' => 5368709120,
            'rate_limit_down_kbps' => 20480,
            'rate_limit_up_kbps' => 20480,
            'price_bdt' => 60.00,
            'simultaneous_use' => 1,
            'created_at' => now(),
        ]);

        $batchId = 'BATCH-POS-TEST';
        HotspotVoucher::create([
            'voucher_id' => 'vch_test_01',
            'tenant_id' => 1,
            'profile_id' => $profile->profile_id,
            'batch_id' => $batchId,
            'code' => 'TEST-POS-1001',
            'pin' => '9988',
            'status' => 'GENERATED',
            'time_limit_seconds' => 86400,
            'data_limit_bytes' => 5368709120,
            'used_time_seconds' => 0,
            'used_bytes' => 0,
            'rate_limit_down_kbps' => 20480,
            'rate_limit_up_kbps' => 20480,
            'price_bdt' => 60.00,
        ]);

        $response = $this->get("/hotspot/print/{$batchId}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Hotspot/VoucherPrint')
            ->where('batchId', $batchId)
            ->has('vouchers', 1)
        );
    }

    public function test_hotspot_single_voucher_print_loads_successfully(): void
    {
        $profile = HotspotProfile::create([
            'tenant_id' => 1,
            'name' => 'Quick Pass',
            'validity_seconds' => 1800,
            'data_limit_bytes' => 524288000,
            'rate_limit_down_kbps' => 5120,
            'rate_limit_up_kbps' => 5120,
            'price_bdt' => 10.00,
            'simultaneous_use' => 1,
            'created_at' => now(),
        ]);

        $code = 'SINGLE-VCH-88';
        HotspotVoucher::create([
            'voucher_id' => 'vch_test_single',
            'tenant_id' => 1,
            'profile_id' => $profile->profile_id,
            'batch_id' => 'BATCH-SINGLE',
            'code' => $code,
            'pin' => '1234',
            'status' => 'GENERATED',
            'time_limit_seconds' => 1800,
            'data_limit_bytes' => 524288000,
            'used_time_seconds' => 0,
            'used_bytes' => 0,
            'rate_limit_down_kbps' => 5120,
            'rate_limit_up_kbps' => 5120,
            'price_bdt' => 10.00,
        ]);

        $response = $this->get("/hotspot/print-voucher/{$code}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Hotspot/VoucherPrint')
            ->has('vouchers', 1)
        );
    }
}
