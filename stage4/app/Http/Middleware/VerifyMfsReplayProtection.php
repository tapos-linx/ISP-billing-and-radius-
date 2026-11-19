<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class VerifyMfsReplayProtection
{
    private const MAX_DRIFT_SECONDS = 300; // 5-minute replay window

    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = (int) $request->header('X-Webhook-Timestamp', 0);
        $nonce = trim((string) $request->header('X-Webhook-Nonce', ''));

        if ($timestamp <= 0 || abs(time() - $timestamp) > self::MAX_DRIFT_SECONDS) {
            return response()->json(['error' => 'Webhook timestamp expired or invalid'], 401);
        }

        if ($nonce === '' || strlen($nonce) < 16) {
            return response()->json(['error' => 'Missing or weak X-Webhook-Nonce'], 400);
        }

        // Atomic SETNX with 600s TTL prevents replay within and beyond drift window
        $nonceKey = "mfs:nonce:{$nonce}";
        $acquired = Redis::set($nonceKey, '1', 'EX', 600, 'NX');
        if (!$acquired) {
            return response()->json(['error' => 'Replay detected: nonce already used'], 409);
        }

        return $next($request);
    }
}
