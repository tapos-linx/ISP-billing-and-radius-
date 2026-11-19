<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Radius\RadiusCoaService;
use App\Services\Radius\RadiusSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Stage 8 Vertical Slice 4:
 * Idempotent bKash & Nagad MFS Webhook Controller.
 * Enforces HMAC-SHA256 verification, Redis SETNX (noeviction), +30d extension,
 * and <3.0s CoA/PoD line restoration.
 */
final class MfsWebhookController extends Controller
{
    public function __construct(
        private readonly RadiusSyncService $radiusSync,
        private readonly RadiusCoaService $radiusCoa
    ) {}

    public function handleBkash(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'bkash', (string) config('services.bkash.app_secret'));
    }

    public function handleNagad(Request $request): JsonResponse
    {
        return $this->processWebhook($request, 'nagad', (string) config('services.nagad.merchant_key'));
    }

    private function processWebhook(Request $request, string $gateway, string $secret): JsonResponse
    {
        $signature = (string) $request->header('X-Webhook-Signature', '');
        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid HMAC-SHA256 signature'], 401);
        }

        $data = $request->validate([
            'trx_id'        => ['required', 'string', 'max:128'],
            'invoice_id'    => ['required', 'uuid'],
            'subscriber_id' => ['required', 'uuid'],
            'amount_bdt'    => ['required', 'numeric', 'min:1'],
        ]);

        // Redis SETNX idempotency guard (requires --maxmemory-policy noeviction)
        $lockKey = "webhook:{$gateway}:{$data['trx_id']}";
        if (!Redis::setnx($lockKey, '1')) {
            return response()->json(['status' => 'duplicate_ignored', 'trx_id' => $data['trx_id']], 200);
        }
        Redis::expire($lockKey, 86400 * 7);

        $subscriber = DB::transaction(function () use ($data, $gateway) {
            DB::table('invoices')
                ->where('invoice_id', $data['invoice_id'])
                ->where('subscriber_id', $data['subscriber_id'])
                ->update([
                    'status'  => 'paid',
                    'paid_at' => now(),
                ]);

            DB::table('payment_webhooks')->insert([
                'subscriber_id' => $data['subscriber_id'],
                'invoice_id'    => $data['invoice_id'],
                'gateway'       => $gateway,
                'trx_id'        => $data['trx_id'],
                'amount_bdt'    => $data['amount_bdt'],
                'hmac_verified' => true,
            ]);

            DB::table('subscribers')
                ->where('subscriber_id', $data['subscriber_id'])
                ->update([
                    'status'     => 'active',
                    'expires_at' => DB::raw("GREATEST(expires_at, NOW()) + INTERVAL '30 days'"),
                ]);

            return DB::table('subscribers')
                ->where('subscriber_id', $data['subscriber_id'])
                ->first();
        });

        if ($subscriber) {
            $password = (string) DB::table('radcheck')
                ->where('subscriber_id', $subscriber->subscriber_id)
                ->where('attribute', 'Cleartext-Password')
                ->value('value');

            $this->radiusSync->syncSubscriber([
                'subscriber_id' => $subscriber->subscriber_id,
                'username'      => $subscriber->username,
                'password'      => $password,
                'rate_limit'    => $subscriber->bandwidth_profile ?? '20M/20M',
                'status'        => 'active',
            ]);

            // Trigger CoA/PoD reset so subscriber exits walled_garden_pool in <3s
            $nas = DB::table('nas')->first();
            if ($nas) {
                $this->radiusCoa->disconnectSession(
                    $subscriber->username,
                    $nas->nasname,
                    $nas->secret,
                    null,
                    (int) $nas->coa_port
                );
            }
        }

        return response()->json(['status' => 'settled', 'trx_id' => $data['trx_id']], 200);
    }
}
