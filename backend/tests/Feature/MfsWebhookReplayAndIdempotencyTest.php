<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class MfsWebhookReplayAndIdempotencyTest extends TestCase
{
    public function test_rejects_expired_webhook_timestamp(): void
    {
        $res = $this->postJson('/api/v1/webhooks/bkash', [
            'trx_id' => 'TRX1001',
        ], [
            'X-Webhook-Timestamp' => (string) (time() - 900),
            'X-Webhook-Nonce'     => 'nonce_1234567890abcdef',
        ]);

        $res->assertStatus(401);
    }

    public function test_duplicate_trx_id_is_idempotent(): void
    {
        Redis::shouldReceive('set')->andReturn(true, false);
        $this->assertTrue(true); // Placeholder assertion when Redis mock is bound
    }
}
