<?php

declare(strict_types=1);

namespace App\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * P1-18: Omnichannel Notification Service (WhatsApp Business Cloud API & Telegram Bot Alerts)
 */
class OmnichannelAlertService
{
    protected string $whatsappToken;

    protected string $whatsappPhoneId;

    protected string $telegramBotToken;

    public function __construct()
    {
        $this->whatsappToken = (string) config('services.whatsapp.token', 'test_whatsapp_token');
        $this->whatsappPhoneId = (string) config('services.whatsapp.phone_id', '10987654321');
        $this->telegramBotToken = (string) config('services.telegram.bot_token', '123456:TEST_BOT_TOKEN');
    }

    /**
     * Dispatch WhatsApp notification via Meta WhatsApp Cloud API
     *
     * @param  array<int, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function sendWhatsAppMessage(string $toPhone, string $templateName, array $parameters = []): array
    {
        // Sanitize phone number (e.g. +88017... -> 88017...)
        $cleanPhone = preg_replace('/\D/', '', $toPhone) ?? '';

        $url = "https://graph.facebook.com/v19.0/{$this->whatsappPhoneId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $cleanPhone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => 'en'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $parameters),
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($this->whatsappToken)->post($url, $payload);

            if ($response->successful()) {
                /** @var array<string, mixed> $body */
                $body = $response->json() ?? [];

                return [
                    'success' => true,
                    'channel' => 'whatsapp',
                    'message_id' => $body['messages'][0]['id'] ?? 'WA-'.strtoupper(bin2hex(random_bytes(6))),
                ];
            }

            return [
                'success' => false,
                'channel' => 'whatsapp',
                'status' => $response->status(),
                'error' => $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsApp API dispatch failed', ['phone' => $toPhone, 'error' => $e->getMessage()]);

            return [
                'success' => false,
                'channel' => 'whatsapp',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Dispatch instant operational or subscriber alert via Telegram Bot API
     *
     * @return array<string, mixed>
     */
    public function sendTelegramAlert(string $chatId, string $message, string $parseMode = 'Markdown'): array
    {
        $url = "https://api.telegram.org/bot{$this->telegramBotToken}/sendMessage";

        try {
            $response = Http::post($url, [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => $parseMode,
            ]);

            if ($response->successful()) {
                /** @var array<string, mixed> $body */
                $body = $response->json() ?? [];

                return [
                    'success' => true,
                    'channel' => 'telegram',
                    'message_id' => $body['result']['message_id'] ?? rand(1000, 9999),
                ];
            }

            return [
                'success' => false,
                'channel' => 'telegram',
                'status' => $response->status(),
                'error' => $response->body(),
            ];
        } catch (\Throwable $e) {
            Log::error('Telegram API dispatch failed', ['chat' => $chatId, 'error' => $e->getMessage()]);

            return [
                'success' => false,
                'channel' => 'telegram',
                'error' => $e->getMessage(),
            ];
        }
    }
}
