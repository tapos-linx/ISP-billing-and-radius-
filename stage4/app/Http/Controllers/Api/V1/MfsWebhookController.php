<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\UnblockSubscriberViaCoaJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class MfsWebhookController extends Controller
{
    public function handleBkash(Request $request): JsonResponse
    {
        return $this->processGatewayWebhook($request, 'bkash', config('services.bkash.webhook_secret'));
    }

    public function handleNagad(Request $request): JsonResponse
    {
        return $this->processGatewayWebhook($request, 'nagad', config('services.nagad.webhook_secret'));
    }

    private function processGatewayWebhook(Request $request, string $gateway, ?string $secret): JsonResponse
    {
        if (empty($secret)) {
            return response()->json(['error' => 'Gateway secret not configured'], 500);
        }

        $signature = (string) $request->header('X-MFS-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (!hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid HMAC signature'], 401);
        }

        $payload = $request->validate([
            'trx_id'        => 'required|string|max:64',
            'subscriber_id' => 'required|uuid',
            'invoice_id'    => 'required|uuid',
            'amount_bdt'    => 'required|numeric|min:1',
        ]);

        // Idempotency lock: guarantees retried webhooks cannot double-credit or double-unblock
        $idempotencyKey = "mfs:tx:{$gateway}:{$payload['trx_id']}";
        if (!Redis::set($idempotencyKey, 'processed', 'EX', 86400 * 7, 'NX')) {
            return response()->json([
                'status' => 'already_processed',
                'trx_id' => $payload['trx_id'],
            ], 200);
        }

        DB::transaction(function () use ($payload, $gateway) {
            DB::table('payment_webhooks')->insert([
                'gateway'       => $gateway,
                'trx_id'        => $payload['trx_id'],
                'subscriber_id' => $payload['subscriber_id'],
                'invoice_id'    => $payload['invoice_id'],
                'amount_bdt'    => $payload['amount_bdt'],
                'status'        => 'verified',
                'created_at'    => now(),
            ]);

            DB::table('invoices')
                ->where('id', $payload['invoice_id'])
                ->where('subscriber_id', $payload['subscriber_id'])
                ->update(['status' => 'paid', 'paid_at' => now()]);

            DB::table('subscribers')
                ->where('subscriber_id', $payload['subscriber_id'])
                ->update(['status' => 'active', 'updated_at' => now()]);
        });

        UnblockSubscriberViaCoaJob::dispatch($payload['subscriber_id']);

        return response()->json(['status' => 'accepted', 'trx_id' => $payload['trx_id']], 202);
    }
}
