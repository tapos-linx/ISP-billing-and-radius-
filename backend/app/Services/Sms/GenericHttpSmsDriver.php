<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * P0-3: Generic Configurable HTTP GET/POST SMS Aggregator Driver
 * Supports placeholder interpolation: [mobile], [message], [templateid], [masking]
 */
class GenericHttpSmsDriver implements SmsGatewayInterface
{
    public function __construct(
        protected ?string $endpoint = null,
        protected string $method = 'POST',
        protected ?string $apiKey = null,
        protected ?string $senderId = null,
        protected ?string $bodyTemplate = null
    ) {
        $this->endpoint = $endpoint ?? config('sms.endpoint', 'https://api.sms-aggregator.com/send');
        $this->method = strtoupper($method ?: (string) config('sms.method', 'POST'));
        $this->apiKey = $apiKey ?? config('sms.api_key', 'test_sms_key');
        $this->senderId = $senderId ?? config('sms.sender_id', 'TaposLinx');
        $this->bodyTemplate = $bodyTemplate ?? config('sms.body_template');
    }

    public function send(string $mobile, string $message, ?string $templateId = null): array
    {
        // Standardize Bangladesh MSISDN: 01XXXXXXXXX -> 8801XXXXXXXXX
        $normalizedMobile = $this->normalizeMsisdn($mobile);

        $url = str_replace(
            ['[mobile]', '[message]', '[templateid]', '[masking]', '[apikey]'],
            [urlencode($normalizedMobile), urlencode($message), urlencode($templateId ?? ''), urlencode($this->senderId ?? ''), urlencode($this->apiKey ?? '')],
            $this->endpoint ?? ''
        );

        $start = microtime(true);
        try {
            if ($this->method === 'GET') {
                $response = Http::timeout(5)->get($url);
            } else {
                $payload = [
                    'api_key' => $this->apiKey,
                    'sender_id' => $this->senderId,
                    'recipient' => $normalizedMobile,
                    'message' => $message,
                ];

                if ($templateId !== null) {
                    $payload['template_id'] = $templateId;
                }

                $response = Http::timeout(5)->post($this->endpoint ?? '', $payload);
            }

            $success = $response->successful();
            $msgId = 'SMS-'.strtoupper(Str::random(10));

            return [
                'success' => $success,
                'message_id' => $success ? $msgId : null,
                'recipient' => $normalizedMobile,
                'status' => $success ? 'delivered' : 'failed',
                'response' => $response->json() ?: $response->body(),
            ];
        } catch (Throwable $e) {
            Log::error('GenericHttpSmsDriver send failure: '.$e->getMessage(), [
                'mobile' => $normalizedMobile,
                'templateId' => $templateId,
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'recipient' => $normalizedMobile,
                'status' => 'error',
                'response' => $e->getMessage(),
            ];
        }
    }

    protected function normalizeMsisdn(string $mobile): string
    {
        $clean = preg_replace('/[^0-9]/', '', $mobile) ?? '';
        if (str_starts_with($clean, '880')) {
            return $clean;
        }
        if (str_starts_with($clean, '01')) {
            return '88'.$clean;
        }

        return $clean;
    }
}
