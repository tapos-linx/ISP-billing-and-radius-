<?php

declare(strict_types=1);

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * P0-3: Mock SMS Driver for QA, testing, and offline testbeds
 */
class MockSmsDriver implements SmsGatewayInterface
{
    /**
     * @var array<int, array{mobile: string, message: string, template_id: ?string, timestamp: string}>
     */
    public static array $sentMessages = [];

    public function send(string $mobile, string $message, ?string $templateId = null): array
    {
        $clean = preg_replace('/[^0-9]/', '', $mobile) ?? '';
        if (str_starts_with($clean, '01')) {
            $clean = '88'.$clean;
        }

        $msgId = 'MOCK-SMS-'.strtoupper(Str::random(8));

        self::$sentMessages[] = [
            'mobile' => $clean,
            'message' => $message,
            'template_id' => $templateId,
            'timestamp' => now()->toIso8601String(),
        ];

        Log::info(sprintf('[Mock SMS Driver] Sent to %s: "%s" [Template: %s]', $clean, $message, $templateId ?? 'N/A'));

        return [
            'success' => true,
            'message_id' => $msgId,
            'recipient' => $clean,
            'status' => 'delivered',
            'response' => ['status' => 'mock_success', 'mock_id' => $msgId],
        ];
    }

    public static function reset(): void
    {
        self::$sentMessages = [];
    }
}
