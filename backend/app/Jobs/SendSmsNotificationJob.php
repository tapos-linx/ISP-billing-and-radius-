<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Sms\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * P0-3: Queueable SMS Notification Worker Job
 */
class SendSmsNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public string $mobile,
        public string $message,
        public ?string $templateId = null
    ) {}

    public function handle(SmsService $smsService): void
    {
        $result = $smsService->send($this->mobile, $this->message, $this->templateId);

        if (! ($result['success'] ?? false)) {
            Log::warning('SendSmsNotificationJob delivery failed', [
                'mobile' => $this->mobile,
                'response' => $result,
            ]);

            throw new \RuntimeException(sprintf('SMS delivery failed to %s: %s', $this->mobile, json_encode($result)));
        }

        Log::info('SendSmsNotificationJob delivered successfully', [
            'mobile' => $this->mobile,
            'message_id' => $result['message_id'] ?? null,
        ]);
    }
}
