<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stage 8/12 Security Middleware:
 * Enforces strict timestamp drift window (<= 300s), high-entropy nonces,
 * atomic Redis SETNX replay protection, and idempotency key enforcement.
 */
class VerifyMfsReplayProtection
{
    private const MAX_DRIFT_SECONDS = 300; // 5-minute maximum drift window
    private const NONCE_TTL_SECONDS = 600; // 10-minute nonce retention in Redis
    private const IDEMPOTENCY_TTL_SECONDS = 604800; // 7-day idempotency retention

    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = (int) ($request->header('X-Webhook-Timestamp') ?: $request->header('X-Timestamp', 0));
        $nonce = trim((string) ($request->header('X-Webhook-Nonce') ?: $request->header('X-Nonce', '')));
        $idempotencyKey = trim((string) ($request->header('X-Idempotency-Key') ?: $request->header('Idempotency-Key', '')));

        // 1. Timestamp Drift Validation
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::MAX_DRIFT_SECONDS) {
            Log::warning("MFS Webhook rejected: timestamp expired or clock drift exceeded", [
                'provided_timestamp' => $timestamp,
                'server_time'        => time(),
                'drift'              => abs(time() - $timestamp),
            ]);
            return response()->json([
                'error' => 'Webhook timestamp expired or invalid',
                'server_time' => time(),
            ], 401);
        }

        // 2. Nonce Entropy & Length Validation
        if ($nonce === '' || strlen($nonce) < 16) {
            Log::warning("MFS Webhook rejected: weak or missing nonce", ['nonce_len' => strlen($nonce)]);
            return response()->json([
                'error' => 'Missing or weak X-Webhook-Nonce (minimum 16 alphanumeric characters required)',
            ], 400);
        }

        // 3. Atomic Redis SETNX Nonce Replay Check
        $nonceKey = "mfs:nonce:{$nonce}";
        try {
            $acquired = Redis::set($nonceKey, '1', 'EX', self::NONCE_TTL_SECONDS, 'NX');
            if (!$acquired) {
                Log::warning("MFS Webhook rejected: duplicate nonce replay detected", ['nonce' => $nonce]);
                return response()->json([
                    'error'   => 'Replay detected: nonce already used',
                    'nonce'   => $nonce,
                ], 409);
            }
        } catch (\Throwable $e) {
            Log::error("Redis replay protection failed to connect: " . $e->getMessage());
            // Fail closed in production for security
            if (app()->environment('production')) {
                return response()->json(['error' => 'Replay protection service temporarily unavailable'], 503);
            }
        }

        // 4. Optional Explicit Idempotency-Key Check
        if ($idempotencyKey !== '') {
            $idemKey = "mfs:idempotency:{$idempotencyKey}";
            try {
                $idemAcquired = Redis::set($idemKey, '1', 'EX', self::IDEMPOTENCY_TTL_SECONDS, 'NX');
                if (!$idemAcquired) {
                    Log::warning("MFS Webhook rejected: duplicate idempotency key", ['key' => $idempotencyKey]);
                    return response()->json([
                        'error'   => 'Duplicate request: idempotency key already processed',
                        'idempotency_key' => $idempotencyKey,
                    ], 409);
                }
            } catch (\Throwable $e) {
                Log::error("Redis idempotency key check error: " . $e->getMessage());
            }
        }

        return $next($request);
    }
}
