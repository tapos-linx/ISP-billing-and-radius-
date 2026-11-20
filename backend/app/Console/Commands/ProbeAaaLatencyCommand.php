<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Metrics\PrometheusMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 4: Synthetic RADIUS AAA Latency Probe Command
 * Probes FreeRADIUS UDP 1812 Access-Request with microsecond accuracy,
 * monitors SLA compliance (< 10ms target), and exports observations to Prometheus.
 */
class ProbeAaaLatencyCommand extends Command
{
    protected $signature = 'metrics:probe-aaa
                            {--iterations=1 : Number of test probes to send}
                            {--interval=0 : Delay in seconds between iterations}
                            {--host= : RADIUS server host (defaults to freeradius_aaa or 127.0.0.1)}
                            {--port=1812 : RADIUS authentication UDP port}
                            {--secret= : RADIUS shared secret}
                            {--username=e2e_live_user : Test probe username}
                            {--password=E2eLivePass@2026 : Test probe password}
                            {--seed-user : Ensure synthetic user exists in radcheck}';

    protected $description = 'Synthetic AAA latency probe measuring RADIUS Access-Accept response time against <10ms SLA';

    public function handle(PrometheusMetricsService $metrics): int
    {
        $iterations = (int) $this->option('iterations');
        $interval = (int) $this->option('interval');
        $host = (string) ($this->option('host') ?: $this->resolveRadiusHost());
        $port = (int) $this->option('port');
        $secret = (string) ($this->option('secret') ?: $this->resolveRadiusSecret());
        $username = (string) $this->option('username');
        $password = (string) $this->option('password');

        if ($this->option('seed-user')) {
            $this->seedProbeSubscriber($username, $password);
        }

        $this->info('===========================================================================');
        $this->info('   SYNTHETIC RADIUS AAA SLA LATENCY PROBE (< 10ms SLA TARGET)');
        $this->info('===========================================================================');
        $this->line(" Target Server : {$host}:{$port}/udp");
        $this->line(" Probe User    : {$username}");
        $this->line(" Iterations    : {$iterations}");
        $this->line('---------------------------------------------------------------------------');

        $totalLatency = 0.0;
        $successCount = 0;
        $slaBreachCount = 0;

        for ($i = 1; $i <= $iterations; $i++) {
            $start = microtime(true);
            $accepted = $this->sendAccessRequest($host, $port, $secret, $username, $password);
            $duration = microtime(true) - $start;

            $totalLatency += $duration;
            $metrics->recordAccessAcceptLatency($duration, $accepted);

            $latencyMs = round($duration * 1000, 2);
            $slaStatus = ($latencyMs <= 10.0)
                ? '<fg=green;options=bold>[PASS: <10ms SLA]</>'
                : '<fg=red;options=bold>[BREACH: >10ms SLA]</>';

            if ($accepted) {
                $successCount++;
                if ($latencyMs > 10.0) {
                    $slaBreachCount++;
                }
                $this->line(sprintf(' [%02d/%02d] Access-Accept | Latency: %6.2f ms | Status: %s', $i, $iterations, $latencyMs, $slaStatus));
            } else {
                $this->line(sprintf(' [%02d/%02d] Access-Reject/Timeout | Latency: %6.2f ms | Status: <fg=yellow>FAILED</>', $i, $iterations, $latencyMs));
            }

            if ($i < $iterations && $interval > 0) {
                sleep($interval);
            }
        }

        $avgLatencyMs = ($iterations > 0) ? round(($totalLatency / $iterations) * 1000, 2) : 0.0;
        $this->info('---------------------------------------------------------------------------');
        $this->info(sprintf(' SUMMARY: %d/%d Accepted | Avg Latency: %.2f ms | SLA Breaches: %d', $successCount, $iterations, $avgLatencyMs, $slaBreachCount));

        return $successCount > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sendAccessRequest(string $host, int $port, string $secret, string $username, string $password): bool
    {
        $radtestBin = config('radius.radtest_bin', 'radtest');

        try {
            $cmd = [$radtestBin];
            if ($port !== 1812) {
                $cmd[] = '-p';
                $cmd[] = (string) $port;
            }
            $cmd = array_merge($cmd, [$username, $password, $host, '0', $secret]);

            $result = Process::timeout(2)->run($cmd);

            if ($result->successful() && str_contains($result->output(), 'Access-Accept')) {
                return true;
            }

            // Fallback: Check radclient syntax with Access-Request
            $radclientBin = config('radius.radclient_bin', 'radclient');
            $payload = "User-Name = \"{$username}\"\nUser-Password = \"{$password}\"\n";
            $res2 = Process::input($payload)
                ->timeout(2)
                ->run([$radclientBin, '-r', '1', '-t', '1', "{$host}:{$port}", 'auth', $secret]);

            return $res2->successful() && str_contains($res2->output(), 'Received Access-Accept');
        } catch (Throwable) {
            return false;
        }
    }

    private function seedProbeSubscriber(string $username, string $password): void
    {
        try {
            $existing = DB::table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Cleartext-Password')
                ->first();

            if ($existing) {
                DB::table('radcheck')
                    ->where('id', $existing->id)
                    ->update(['value' => $password]);
            } else {
                DB::table('radcheck')->insert([
                    'subscriber_id' => (string) Str::uuid(),
                    'username' => $username,
                    'attribute' => 'Cleartext-Password',
                    'op' => ':=',
                    'value' => $password,
                ]);
            }
            $this->comment(' [SETUP] Verified synthetic test subscriber in radcheck table.');
        } catch (Throwable $e) {
            $this->warn(' [SETUP] Notice: could not seed probe user directly: '.$e->getMessage());
        }
    }

    private function resolveRadiusHost(): string
    {
        return 'freeradius_aaa';
    }

    private function resolveRadiusSecret(): string
    {
        if (file_exists('/run/secrets/radius_secret')) {
            $sec = trim((string) file_get_contents('/run/secrets/radius_secret'));
            if ($sec !== '') {
                return $sec;
            }
        }

        return env('RADIUS_SECRET', 'secure_radius_secret_key_2026');
    }
}
