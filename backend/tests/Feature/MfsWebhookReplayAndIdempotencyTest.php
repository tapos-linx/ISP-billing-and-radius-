<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Phase 1 Security Hardening Test:
 * Validates HMAC-SHA256 verification, timestamp-window checks,
 * high-entropy nonces, and Redis SETNX idempotency locks.
 */
class MfsWebhookReplayAndIdempotencyTest extends TestCase
{
    private string $secret = 'sandbox_bkash_secret_2026';

    public function test_rejects_expired_webhook_timestamp(): void
    {
        $payload = ['trx_id' => 'TRX_EXP_001'];
        $res = $this->postJson('/api/v1/webhooks/bkash', $payload, [
            'X-Webhook-Timestamp' => (string) (time() - 900), // 15 min old (> 300s)
            'X-Webhook-Nonce' => 'nonce_1234567890abcdef',
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), $this->secret),
        ]);

        $res->assertStatus(401);
        $res->assertJsonFragment(['error' => 'Webhook timestamp expired or invalid']);
    }

    public function test_rejects_future_clock_drift(): void
    {
        $payload = ['trx_id' => 'TRX_DRIFT_001'];
        $res = $this->postJson('/api/v1/webhooks/bkash', $payload, [
            'X-Webhook-Timestamp' => (string) (time() + 900), // 15 min in future
            'X-Webhook-Nonce' => 'nonce_1234567890abcdef',
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), $this->secret),
        ]);

        $res->assertStatus(401);
    }

    public function test_rejects_missing_or_short_nonce(): void
    {
        $payload = ['trx_id' => 'TRX_NONCE_001'];
        $res = $this->postJson('/api/v1/webhooks/bkash', $payload, [
            'X-Webhook-Timestamp' => (string) time(),
            'X-Webhook-Nonce' => 'short_123', // < 16 chars
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), $this->secret),
        ]);

        $res->assertStatus(400);
        $res->assertJsonFragment(['error' => 'Missing or weak X-Webhook-Nonce (minimum 16 alphanumeric characters required)']);
    }

    public function test_rejects_replayed_nonce(): void
    {
        $nonce = 'nonce_unique_replay_test_'.bin2hex(random_bytes(6));
        $payload = ['trx_id' => 'TRX_REPLAY_NONCE_001'];
        $headers = [
            'X-Webhook-Timestamp' => (string) time(),
            'X-Webhook-Nonce' => $nonce,
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), $this->secret),
        ];

        // First attempt (nonce stored in Redis)
        Redis::set("mfs:nonce:{$nonce}", '1', 'EX', 600, 'NX');

        // Immediate replay of same nonce
        $res = $this->postJson('/api/v1/webhooks/bkash', $payload, $headers);
        $res->assertStatus(409);
        $res->assertJsonFragment(['error' => 'Replay detected: nonce already used']);
    }

    public function test_rejects_replayed_idempotency_key(): void
    {
        $idemKey = 'idem_key_'.bin2hex(random_bytes(8));
        $nonce = 'nonce_idem_test_'.bin2hex(random_bytes(6));
        $payload = ['trx_id' => 'TRX_IDEM_001'];

        Redis::set("mfs:idempotency:{$idemKey}", '1', 'EX', 600, 'NX');

        $res = $this->postJson('/api/v1/webhooks/bkash', $payload, [
            'X-Webhook-Timestamp' => (string) time(),
            'X-Webhook-Nonce' => $nonce,
            'X-Idempotency-Key' => $idemKey,
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), $this->secret),
        ]);

        $res->assertStatus(409);
        $res->assertJsonFragment(['error' => 'Duplicate request: idempotency key already processed']);
    }

    public function test_rejects_invalid_hmac_signature(): void
    {
        $nonce = 'nonce_sig_test_'.bin2hex(random_bytes(6));
        $payload = [
            'trx_id' => 'TRX_INVALID_SIG_001',
            'subscriber_id' => '00000000-0000-0000-0000-000000000001',
            'invoice_id' => '00000000-0000-0000-0000-000000000002',
            'amount_bdt' => 1050.00,
        ];

        $res = $this->postJson('/api/v1/webhooks/bkash', $payload, [
            'X-Webhook-Timestamp' => (string) time(),
            'X-Webhook-Nonce' => $nonce,
            'X-Webhook-Signature' => 'invalid_forged_hmac_sha256_hash_1234567890abcdef',
        ]);

        $res->assertStatus(401);
        $res->assertJsonFragment(['error' => 'Invalid HMAC-SHA256 signature']);
    }

    public function test_nagad_rejects_invalid_hmac_signature(): void
    {
        $nonce = 'nonce_nagad_test_'.bin2hex(random_bytes(6));
        $payload = [
            'trx_id' => 'TRX_NAGAD_INVALID_001',
            'subscriber_id' => '00000000-0000-0000-0000-000000000001',
            'invoice_id' => '00000000-0000-0000-0000-000000000002',
            'amount_bdt' => 1050.00,
        ];

        $res = $this->postJson('/api/v1/webhooks/nagad', $payload, [
            'X-Webhook-Timestamp' => (string) time(),
            'X-Webhook-Nonce' => $nonce,
            'X-Webhook-Signature' => 'forged_nagad_signature_abcdef',
        ]);

        $res->assertStatus(401);
    }
}
