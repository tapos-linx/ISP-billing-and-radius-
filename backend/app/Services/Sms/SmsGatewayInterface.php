<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * P0-3: Carrier SMS Gateway Interface
 */
interface SmsGatewayInterface
{
    /**
     * Send an SMS notification to a given mobile recipient.
     *
     * @return array{
     *   success: bool,
     *   message_id: ?string,
     *   recipient: string,
     *   status: string,
     *   response: mixed
     * }
     */
    public function send(string $mobile, string $message, ?string $templateId = null): array;
}
