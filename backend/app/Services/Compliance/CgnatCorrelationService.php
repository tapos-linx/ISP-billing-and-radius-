<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Models\CgnatBinding;
use App\Models\Subscriber;
use Carbon\Carbon;

/**
 * P1-10: Carrier Grade NAT (CGNAT) Port Block Allocation & IPDR Session Correlation Service
 */
class CgnatCorrelationService
{
    /**
     * Allocate a deterministic port block on public CGNAT IPv4 pool
     */
    public function allocatePortBlock(
        string $subscriberId,
        int $tenantId,
        string $privateIp,
        string $publicIp,
        int $portCount = 1000
    ): CgnatBinding {
        $now = Carbon::now();

        // Find max port allocated so far on this public IP
        $lastBinding = CgnatBinding::withoutGlobalScopes()
            ->where('public_ip', $publicIp)
            ->orderByDesc('port_end')
            ->first();

        $startPort = $lastBinding ? ($lastBinding->port_end + 1) : 10000;
        $endPort = $startPort + $portCount - 1;

        if ($endPort > 65535) {
            $startPort = 10000;
            $endPort = $startPort + $portCount - 1;
        }

        return CgnatBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'subscriber_id' => $subscriberId,
            'private_ip' => $privateIp,
            'public_ip' => $publicIp,
            'port_start' => $startPort,
            'port_end' => $endPort,
            'allocated_at' => $now,
            'retained_until' => $now->copy()->addDays(730), // BTRC 2-year retention
        ]);
    }

    /**
     * Release active CGNAT port block binding
     */
    public function releasePortBlock(string $subscriberId, string $privateIp): bool
    {
        $binding = CgnatBinding::withoutGlobalScopes()
            ->where('subscriber_id', $subscriberId)
            ->where('private_ip', $privateIp)
            ->whereNull('released_at')
            ->latest('allocated_at')
            ->first();

        if ($binding) {
            $binding->released_at = Carbon::now();
            $binding->save();

            return true;
        }

        return false;
    }

    /**
     * BTRC Cyber-Crime & Abuse Forensic Correlation:
     * Discovers which subscriber was assigned the public IP + source port at the exact UTC timestamp
     */
    public function correlateSession(string $publicIp, int $port, Carbon $timestamp): ?array
    {
        $binding = CgnatBinding::withoutGlobalScopes()
            ->where('public_ip', $publicIp)
            ->where('port_start', '<=', $port)
            ->where('port_end', '>=', $port)
            ->where('allocated_at', '<=', $timestamp)
            ->where(function ($q) use ($timestamp) {
                $q->whereNull('released_at')
                    ->orWhere('released_at', '>=', $timestamp);
            })
            ->latest('allocated_at')
            ->first();

        if (! $binding) {
            return null;
        }

        $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $binding->subscriber_id)->first();

        return [
            'found' => true,
            'public_ip' => $publicIp,
            'port' => $port,
            'query_timestamp' => $timestamp->toIso8601String(),
            'subscriber_id' => $binding->subscriber_id,
            'username' => $subscriber ? $subscriber->username : 'Unknown',
            'private_ip' => $binding->private_ip,
            'port_block_allocated' => "{$binding->port_start}-{$binding->port_end}",
            'allocated_at' => $binding->allocated_at ? $binding->allocated_at->toIso8601String() : null,
            'released_at' => $binding->released_at ? $binding->released_at->toIso8601String() : 'Active',
            'retained_until' => $binding->retained_until ? $binding->retained_until->toIso8601String() : null,
        ];
    }
}
