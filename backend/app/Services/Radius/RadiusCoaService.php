<?php

declare(strict_types=1);

namespace App\Services\Radius;

use App\Services\Metrics\PrometheusMetricsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Phase 4 Instrumented CoA Service:
 * Dispatches RFC 5176 Change-of-Authorization (CoA) and Disconnect-Request (PoD)
 * packets over UDP port 3799 (<500ms SLA target).
 * Records real-time Prometheus latency & success/failure metrics.
 * Dead-letters failed attempts to `failed_coa_jobs` table.
 */
class RadiusCoaService
{
    private const DEFAULT_COA_PORT = 3799;

    public function __construct(
        private readonly ?PrometheusMetricsService $metricsService = null
    ) {}

    private function getMetrics(): PrometheusMetricsService
    {
        return $this->metricsService ?? app(PrometheusMetricsService::class);
    }

    /**
     * Terminate an active subscriber PPPoE session (PoD).
     */
    public function disconnectSession(
        string $username,
        string $nasIp,
        string $secret,
        ?string $acctSessionId = null,
        int $coaPort = self::DEFAULT_COA_PORT,
        ?string $subscriberId = null
    ): bool {
        $payload = "User-Name = \"{$username}\"\n";
        if ($acctSessionId !== null && $acctSessionId !== '') {
            $payload .= "Acct-Session-Id = \"{$acctSessionId}\"\n";
        }

        try {
            return $this->sendRadclient($nasIp, $coaPort, 'disconnect', $secret, $payload);
        } catch (Throwable $e) {
            Log::warning("RadiusCoaService disconnect failed: " . $e->getMessage(), [
                'username' => $username,
                'nasIp'    => $nasIp,
            ]);

            if ($subscriberId !== null) {
                $this->recordDeadLetter($subscriberId, $username, $nasIp, 'disconnect', $e->getMessage());
            }

            return false;
        }
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
        int $coaPort = self::DEFAULT_COA_PORT,
        ?string $subscriberId = null
    ): bool {
        $payload = "User-Name = \"{$username}\"\n";
        if ($acctSessionId !== null && $acctSessionId !== '') {
            $payload .= "Acct-Session-Id = \"{$acctSessionId}\"\n";
        }
        $payload .= "Mikrotik-Rate-Limit = \"{$rateLimit}\"\n";

        try {
            return $this->sendRadclient($nasIp, $coaPort, 'coa', $secret, $payload);
        } catch (Throwable $e) {
            Log::warning("RadiusCoaService changeBandwidth failed: " . $e->getMessage(), [
                'username' => $username,
                'nasIp'    => $nasIp,
            ]);

            if ($subscriberId !== null) {
                $this->recordDeadLetter($subscriberId, $username, $nasIp, 'coa', $e->getMessage());
            }

            return false;
        }
    }

    /**
     * Legacy / generic CoA or PoD helper.
     */
    public function sendCoaOrPod(
        string $nasIp,
        string $secret,
        string $username,
        string $action = 'disconnect',
        array $attrs = []
    ): bool {
        $payload = "User-Name = \"{$username}\"\n";
        foreach ($attrs as $k => $v) {
            $payload .= "{$k} = \"{$v}\"\n";
        }

        $packetType = $action === 'disconnect' ? 'disconnect' : 'coa';
        return $this->sendRadclient($nasIp, self::DEFAULT_COA_PORT, $packetType, $secret, $payload);
    }

    /**
     * Dead-letter log for unreachable NAS or timeout.
     */
    public function recordDeadLetter(
        string $subscriberId,
        string $username,
        string $nasIp,
        string $action,
        string $reason
    ): void {
        try {
            DB::table('failed_coa_jobs')->insert([
                'subscriber_id' => $subscriberId,
                'username'      => $username,
                'nas_ip'        => $nasIp,
                'action'        => $action,
                'error_reason'  => substr($reason, 0, 500),
                'created_at'    => now(),
            ]);
        } catch (Throwable $e) {
            Log::error("Failed to record dead-letter CoA: " . $e->getMessage());
        }
    }

    private function sendRadclient(
        string $nasIp,
        int $coaPort,
        string $packetType,
        string $secret,
        string $attributes
    ): bool {
        $target = "{$nasIp}:{$coaPort}";
        $radclientBin = config('radius.radclient_bin', 'radclient');

        $start = microtime(true);
        try {
            $result = Process::input($attributes)
                ->timeout(2)
                ->run([$radclientBin, '-r', '1', '-t', '1', $target, $packetType, $secret]);

            $duration = microtime(true) - $start;

            if (!$result->successful()) {
                $this->getMetrics()->recordCoaLatency($duration, $packetType, false);
                throw new RuntimeException(
                    "RADIUS {$packetType} failed for {$target}: " . trim($result->errorOutput() ?: $result->output())
                );
            }

            $success = str_contains($result->output(), 'Received Disconnect-ACK')
                || str_contains($result->output(), 'Received CoA-ACK');

            $this->getMetrics()->recordCoaLatency($duration, $packetType, $success);
            return $success;
        } catch (Throwable $e) {
            $duration = microtime(true) - $start;
            $this->getMetrics()->recordCoaLatency($duration, $packetType, false);
            throw $e;
        }
    }
}
