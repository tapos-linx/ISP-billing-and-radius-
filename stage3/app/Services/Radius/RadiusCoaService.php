<?php

namespace App\Services\Radius;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RadiusCoaService
{
    public function sendCoaOrPod(string $nasIp, string $secret, string $username, string $action = 'disconnect', array $attrs = []): bool
    {
        $radclient = config('radius.radclient_bin', '/usr/bin/radclient');
        $port = (int) config('radius.coa_port', 3799);
        $packetType = $action === 'disconnect' ? 'disconnect' : 'coa';

        $lines = ["User-Name = \"{$username}\""];
        foreach ($attrs as $k => $v) {
            $lines[] = "{$k} = \"{$v}\"";
        }
        $payload = implode("\n", $lines) . "\n";

        $cmd = sprintf(
            'printf %s | %s -r 3 -t 2 %s:%d %s %s',
            escapeshellarg($payload),
            escapeshellcmd($radclient),
            escapeshellarg($nasIp),
            $port,
            escapeshellarg($packetType),
            escapeshellarg($secret)
        );

        exec($cmd . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            Log::warning('RADIUS CoA failed', ['username' => $username, 'nas' => $nasIp, 'output' => $output]);
            return false;
        }
        return true;
    }

    public function recordDeadLetter(string $subscriberId, string $username, string $nasIp, string $action, string $reason): void
    {
        DB::table('failed_coa_jobs')->insert([
            'subscriber_id' => $subscriberId,
            'username'      => $username,
            'nas_ip'        => $nasIp,
            'action'        => $action,
            'error_reason'  => substr($reason, 0, 500),
            'created_at'    => now(),
        ]);
    }
}
