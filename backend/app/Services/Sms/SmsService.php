<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * P0-3: Carrier SMS Manager Service
 */
class SmsService
{
    protected SmsGatewayInterface $driver;

    public function __construct(?SmsGatewayInterface $driver = null)
    {
        $this->driver = $driver ?? $this->resolveDriver();
    }

    public function send(string $mobile, string $message, ?string $templateId = null): array
    {
        return $this->driver->send($mobile, $message, $templateId);
    }

    public function sendOtp(string $mobile, string $otpCode): array
    {
        $msg = sprintf('Your Tapos Linx verification code is %s. Valid for 5 minutes. Do not share this OTP.', $otpCode);

        return $this->driver->send($mobile, $msg, 'OTP_VERIFICATION');
    }

    public function sendPaymentConfirmation(string $mobile, float $amount, string $trxId): array
    {
        $msg = sprintf('Payment of BDT %.2f received (TrxID: %s). Your Tapos Linx broadband connection is active. Thank you!', $amount, $trxId);

        return $this->driver->send($mobile, $msg, 'PAYMENT_CONFIRMATION');
    }

    public function sendExpiryAlert(string $mobile, int $daysRemaining, string $username): array
    {
        $msg = sprintf('Dear %s, your broadband subscription expires in %d days. Recharge via bKash/Nagad/Card to avoid disconnection.', $username, $daysRemaining);

        return $this->driver->send($mobile, $msg, 'EXPIRY_ALERT');
    }

    protected function resolveDriver(): SmsGatewayInterface
    {
        $driverType = config('sms.driver', 'mock');

        return match ($driverType) {
            'generic_http' => app(GenericHttpSmsDriver::class),
            'mock' => app(MockSmsDriver::class),
            default => app(MockSmsDriver::class),
        };
    }
}
