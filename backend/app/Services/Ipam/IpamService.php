<?php

declare(strict_types=1);

namespace App\Services\Ipam;

use App\Models\IpAssignmentHistory;
use App\Models\Subscriber;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * P0-12: Full IPAM IPv4/IPv6 Prefix Delegation & Forensic Abuse History Service
 */
class IpamService
{
    /**
     * Split an IPv4 CIDR block into two child subnets (e.g., /24 -> two /25s)
     */
    public function splitSubnet(string $cidr): array
    {
        [$ip, $mask] = explode('/', $cidr);
        $maskInt = (int) $mask;

        if ($maskInt < 8 || $maskInt >= 31) {
            throw new InvalidArgumentException("Cannot split CIDR /{$maskInt}. Must be between /8 and /30.");
        }

        $newMask = $maskInt + 1;
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            throw new InvalidArgumentException("Invalid IP address: {$ip}");
        }

        // Subnet 1: same network base with new mask
        $subnet1Ip = long2ip($ipLong & (~((1 << (32 - $maskInt)) - 1)));
        // Subnet 2: base + 2^(32 - newMask)
        $subnet2Ip = long2ip(ip2long($subnet1Ip) + (1 << (32 - $newMask)));

        $hostCount = (1 << (32 - $newMask)) - 2;

        return [
            'original_cidr' => $cidr,
            'child_mask' => $newMask,
            'usable_hosts_per_subnet' => max(0, $hostCount),
            'subnets' => [
                "{$subnet1Ip}/{$newMask}",
                "{$subnet2Ip}/{$newMask}",
            ],
        ];
    }

    /**
     * Merge two adjacent sibling subnets back into their parent supernet
     */
    public function mergeSubnets(string $cidr1, string $cidr2): array
    {
        [$ip1, $mask1] = explode('/', $cidr1);
        [$ip2, $mask2] = explode('/', $cidr2);

        if ($mask1 !== $mask2) {
            throw new InvalidArgumentException('Only subnets of the same prefix length can be merged.');
        }

        $maskInt = (int) $mask1;
        $parentMask = $maskInt - 1;

        $long1 = ip2long($ip1);
        $long2 = ip2long($ip2);

        if ($long1 === false || $long2 === false) {
            throw new InvalidArgumentException('Invalid IP addresses provided.');
        }

        $parentLong = min($long1, $long2) & (~((1 << (32 - $parentMask)) - 1));
        $parentIp = long2ip($parentLong);
        $usableHosts = (1 << (32 - $parentMask)) - 2;

        return [
            'parent_cidr' => "{$parentIp}/{$parentMask}",
            'prefix_length' => $parentMask,
            'usable_hosts' => $usableHosts,
            'merged_children' => [$cidr1, $cidr2],
        ];
    }

    /**
     * Allocate IPv6 Delegated Prefix (/64) from parent pool (/48 or /56)
     */
    public function allocateIpv6DelegatedPrefix(string $parentPrefix, int $subscriberIndex): string
    {
        // Example: '2001:db8:1000::/48', index 1 => '2001:db8:1000:1::/64'
        $prefixBase = explode('/', $parentPrefix)[0];
        $prefixBase = rtrim($prefixBase, ':');

        $hexIndex = dechex($subscriberIndex);

        return sprintf('%s:%s::/64', $prefixBase, $hexIndex);
    }

    /**
     * Record IP assignment to subscriber session in immutable audit history
     */
    public function recordIpAssignment(
        string $subscriberId,
        int $tenantId,
        string $framedIp,
        ?string $ipv6Prefix = null,
        ?string $nasIp = null,
        ?string $mac = null,
        ?string $sessionId = null
    ): IpAssignmentHistory {
        $now = Carbon::now();

        return IpAssignmentHistory::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'subscriber_id' => $subscriberId,
            'framed_ip' => $framedIp,
            'delegated_ipv6_prefix' => $ipv6Prefix,
            'nas_ip' => $nasIp,
            'mac_address' => $mac,
            'session_id' => $sessionId,
            'assigned_at' => $now,
            'retained_until' => $now->copy()->addDays(730), // 2-year BTRC compliance
        ]);
    }

    /**
     * Mark an IP assignment as released on PPPoE session termination
     */
    public function releaseIpAssignment(string $framedIp, ?string $subscriberId = null): bool
    {
        $query = IpAssignmentHistory::withoutGlobalScopes()
            ->where('framed_ip', $framedIp)
            ->whereNull('released_at');

        if ($subscriberId !== null) {
            $query->where('subscriber_id', $subscriberId);
        }

        $active = $query->latest('assigned_at')->first();
        if ($active) {
            $active->released_at = Carbon::now();
            $active->save();

            return true;
        }

        return false;
    }

    /**
     * Regulatory / BTRC Cyber-Abuse Lookup:
     * Discovers which subscriber owned a given IP at a precise UTC timestamp
     */
    public function lookupAbuseHistory(string $targetIp, Carbon $timestamp): ?array
    {
        $history = IpAssignmentHistory::withoutGlobalScopes()
            ->where('framed_ip', $targetIp)
            ->where('assigned_at', '<=', $timestamp)
            ->where(function ($q) use ($timestamp) {
                $q->whereNull('released_at')
                    ->orWhere('released_at', '>=', $timestamp);
            })
            ->latest('assigned_at')
            ->first();

        if (! $history) {
            return null;
        }

        $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $history->subscriber_id)->first();

        return [
            'found' => true,
            'target_ip' => $targetIp,
            'query_timestamp' => $timestamp->toIso8601String(),
            'subscriber_id' => $history->subscriber_id,
            'username' => $subscriber ? $subscriber->username : 'Unknown',
            'mac_address' => $history->mac_address,
            'nas_ip' => $history->nas_ip,
            'session_id' => $history->session_id,
            'assigned_at' => $history->assigned_at ? $history->assigned_at->toIso8601String() : null,
            'released_at' => $history->released_at ? $history->released_at->toIso8601String() : 'Still Active',
            'delegated_ipv6_prefix' => $history->delegated_ipv6_prefix,
        ];
    }
}
