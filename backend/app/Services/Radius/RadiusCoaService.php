<?php

declare(strict_types=1);

namespace App\Services\Radius;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Stage 8 Vertical Slice 3:
 * Dispatches RFC 5176 Change-of-Authorization (CoA) and Disconnect-Request (PoD)
 * packets over UDP port 3799 (<500ms target).
 */
final class RadiusCoaService
{
    private const DEFAULT_COA_PORT = 3799;

    /**
     * Terminate an active subscriber PPPoE session (PoD).
     */
    public function disconnectSession(
        string $username,
        string $nasIp,
        string $secret,
        ?string $acctSessionId = null,
        int $coaPort = self::DEFAULT_COA_PORT
    ): bool {
        $payload = "User-Name = \"{$username}\"\n";
        if ($acctSessionId !== null && $acctSessionId !== '') {
            $payload .= "Acct-Session-Id = \"{$acctSessionId}\"\n";
        }

        return $this->sendRadclient($nasIp, $coaPort, 'disconnect', $secret, $payload);
    }

    /**
     * Dynamically resize active PPPoE bandwidth without dropping the session (CoA).
     */
    public function changeBandwidth(
        string $username,
        string $nasIp,
        string $secret,
        string $rateLimit,
        ?string $acctSessionId = null,
        int $coaPort = self::DEFAULT_COA_PORT
    ): bool {
        $payload = "User-Name = \"{$username}\"\n";
        if ($acctSessionId !== null && $acctSessionId !== '') {
            $payload .= "Acct-Session-Id = \"{$acctSessionId}\"\n";
        }
        $payload .= "Mikrotik-Rate-Limit = \"{$rateLimit}\"\n";

        return $this->sendRadclient($nasIp, $coaPort, 'coa', $secret, $payload);
    }

    private function sendRadclient(
        string $nasIp,
        int $coaPort,
        string $packetType,
        string $secret,
        string $attributes
    ): bool {
        $target = "{$nasIp}:{$coaPort}";
        $result = Process::input($attributes)
            ->timeout(3)
            ->run(['radclient', '-r', '2', '-t', '1', $target, $packetType, $secret]);

        if (!$result->successful()) {
            throw new RuntimeException(
                "RADIUS {$packetType} failed for {$target}: " . trim($result->errorOutput() ?: $result->output())
            );
        }

        return str_contains($result->output(), 'Received Disconnect-ACK')
            || str_contains($result->output(), 'Received CoA-ACK');
    }
}
