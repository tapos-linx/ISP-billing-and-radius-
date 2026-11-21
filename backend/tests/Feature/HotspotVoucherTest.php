<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Release 2.0 (Epic 1): Wi-Fi Hotspot Voucher & Fast-Path AAA Feature Tests
 */
class HotspotVoucherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure default profile exists
        if (! DB::table('hotspot_profiles')->where('profile_id', 1)->exists()) {
            DB::table('hotspot_profiles')->insert([
                'profile_id' => 1,
                'tenant_id' => 1,
                'name' => '1-Hour Express Pass',
                'validity_seconds' => 3600,
                'data_limit_bytes' => 1073741824,
                'rate_limit_down_kbps' => 10240,
                'rate_limit_up_kbps' => 10240,
                'price_bdt' => 20.00,
                'simultaneous_use' => 1,
                'created_at' => now(),
            ]);
        }
    }

    public function test_hotspot_profiles_can_be_retrieved(): void
    {
        $response = $this->getJson('/api/v1/hotspot/profiles');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertNotEmpty($response->json('data'));
    }

    public function test_can_generate_voucher_batch_and_prewarm_redis(): void
    {
        $batchId = 'TEST_BATCH_'.time();
        $payload = [
            'batch_id' => $batchId,
            'profile_id' => 1,
            'count' => 5,
            'custom_price' => 25.00,
        ];

        $response = $this->postJson('/api/v1/hotspot/vouchers/generate', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('batch_id', $batchId)
            ->assertJsonPath('count', 5);

        $vouchers = $response->json('vouchers');
        $this->assertCount(5, $vouchers);

        $firstCode = $vouchers[0]['code'];
        $this->assertStringStartsWith('WIFI-', $firstCode);

        // Verify pre-warmed in Redis
        $redisVal = Redis::get("hotspot:voucher:{$firstCode}");
        $this->assertNotNull($redisVal, "Voucher {$firstCode} was not pre-warmed in Redis");
    }

    public function test_fast_path_voucher_verification_and_first_login_activation(): void
    {
        $batchId = 'TEST_AUTH_'.time();
        $genResponse = $this->postJson('/api/v1/hotspot/vouchers/generate', [
            'batch_id' => $batchId,
            'profile_id' => 1,
            'count' => 1,
        ]);

        $voucher = $genResponse->json('vouchers.0');
        $code = $voucher['code'];

        // Perform authentication
        $authResponse = $this->postJson('/api/v1/hotspot/vouchers/auth-verify', [
            'code' => $code,
            'mac_address' => 'AA:BB:CC:11:22:33',
        ]);

        $authResponse->assertStatus(200)
            ->assertJsonPath('authorized', true)
            ->assertJsonPath('status', 'ACTIVE')
            ->assertJsonPath('mac_address', 'AA:BB:CC:11:22:33')
            ->assertJsonPath('attributes.Mikrotik-Rate-Limit', '10M/10M')
            ->assertJsonPath('attributes.WISPr-Bandwidth-Max-Down', 10485760)
            ->assertJsonPath('attributes.WISPr-Bandwidth-Max-Up', 10485760);
    }

    public function test_voucher_rejects_invalid_code(): void
    {
        $response = $this->postJson('/api/v1/hotspot/vouchers/auth-verify', [
            'code' => 'WIFI-NONEXISTENT',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('authorized', false)
            ->assertJsonPath('reason', 'VOUCHER_NOT_FOUND');
    }

    public function test_esc_pos_receipt_rendering(): void
    {
        $batchId = 'TEST_POS_'.time();
        $genResponse = $this->postJson('/api/v1/hotspot/vouchers/generate', [
            'batch_id' => $batchId,
            'profile_id' => 1,
            'count' => 1,
        ]);

        $code = $genResponse->json('vouchers.0.code');

        $receiptResponse = $this->get("/api/v1/hotspot/vouchers/{$code}/receipt");

        $receiptResponse->assertStatus(200);
        $content = $receiptResponse->getContent();

        $this->assertStringContainsString('TAPOS HIGH-SPEED WI-FI', $content);
        $this->assertStringContainsString('PUBLIC HOTSPOT PASS', $content);
        $this->assertStringContainsString($code, $content);
        $this->assertStringContainsString('NBR 5% VAT (Mushak-6.3)', $content);
        $this->assertStringContainsString('bKash Pay QR Payload', $content);
    }

    public function test_voucher_quota_consumption_and_depletion(): void
    {
        $batchId = 'TEST_CONSUME_'.time();
        $genResponse = $this->postJson('/api/v1/hotspot/vouchers/generate', [
            'batch_id' => $batchId,
            'profile_id' => 1,
            'count' => 1,
        ]);

        $code = $genResponse->json('vouchers.0.code');

        // Burn 4000 seconds (time limit is 3600s)
        $consumeResponse = $this->postJson("/api/v1/hotspot/vouchers/{$code}/consume", [
            'added_seconds' => 4000,
            'added_bytes' => 100000,
        ]);

        $consumeResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'DEPLETED')
            ->assertJsonPath('data.depleted', true);
    }
}
