<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * P1-19: Outbound Event Webhook Dispatcher & HMAC-SHA256 Cryptographic Signing Service
 */
class OutboundWebhookService
{
    /**
     * Register a new outbound webhook subscriber endpoint
     *
     * @param  array<int, string>  $events
     */
    public function subscribe(int $tenantId, string $targetUrl, array $events, ?string $secret = null): WebhookSubscription
    {
        $signingSecret = $secret ?? Str::random(32);

        /** @var WebhookSubscription $subscription */
        $subscription = WebhookSubscription::withoutGlobalScopes()->create([
            'subscription_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'target_url' => $targetUrl,
            'secret' => $signingSecret,
            'events' => $events,
            'is_active' => true,
            'created_at' => Carbon::now(),
        ]);

        return $subscription;
    }

    /**
     * Dispatch an outbound event to all active matching webhook subscribers
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function dispatchEvent(int $tenantId, string $eventType, array $payload): array
    {
        $subscriptions = WebhookSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $deliveries = [];

        foreach ($subscriptions as $subscription) {
            $subscribedEvents = (array) ($subscription->events ?? []);
            if (! in_array('*', $subscribedEvents, true) && ! in_array($eventType, $subscribedEvents, true)) {
                continue;
            }

            $deliveries[] = $this->deliverToSubscription($subscription, $eventType, $payload);
        }

        return $deliveries;
    }

    /**
     * Execute HTTP POST with HMAC-SHA256 signature to subscriber endpoint
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function deliverToSubscription(WebhookSubscription $subscription, string $eventType, array $payload): array
    {
        $jsonPayload = (string) json_encode($payload);
        $timestamp = (string) Carbon::now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$jsonPayload}", (string) $subscription->secret);

        $deliveryId = (string) Str::uuid();
        $status = 'failed';
        $responseStatus = 500;
        $responseBody = null;

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Carrier-Event' => $eventType,
                    'X-Carrier-Timestamp' => $timestamp,
                    'X-Carrier-Signature' => "sha256={$signature}",
                ])
                ->post($subscription->target_url, $payload);

            $responseStatus = $response->status();
            $responseBody = substr($response->body(), 0, 1024);
            $status = $response->successful() ? 'success' : 'failed';
        } catch (\Throwable $e) {
            $responseBody = $e->getMessage();
            Log::warning('Webhook delivery failed', [
                'subscription_id' => $subscription->subscription_id,
                'target' => $subscription->target_url,
                'error' => $e->getMessage(),
            ]);
        }

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::withoutGlobalScopes()->create([
            'delivery_id' => $deliveryId,
            'subscription_id' => $subscription->subscription_id,
            'tenant_id' => $subscription->tenant_id,
            'event_type' => $eventType,
            'payload' => $payload,
            'response_status' => $responseStatus,
            'response_body' => $responseBody,
            'attempt_count' => 1,
            'status' => $status,
            'delivered_at' => $status === 'success' ? Carbon::now() : null,
            'created_at' => Carbon::now(),
        ]);

        return [
            'delivery_id' => $delivery->delivery_id,
            'subscription_id' => $subscription->subscription_id,
            'status' => $status,
            'response_code' => $responseStatus,
        ];
    }
}
