<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Models\BgpPeer;
use App\Models\BgpTelemetry;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * P2-2: BGP / OSPF Peering Analytics & IXP Traffic Ratio Optimization Service
 */
class BgpPeeringAnalysisService
{
    /**
     * Register a BGP peering neighbor session
     *
     * @param  array<string, mixed>  $data
     */
    public function registerPeer(int $tenantId, array $data): BgpPeer
    {
        /** @var BgpPeer $peer */
        $peer = BgpPeer::withoutGlobalScopes()->create([
            'peer_id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'peer_name' => (string) $data['peer_name'],
            'peer_type' => (string) ($data['peer_type'] ?? 'ixp'),
            'remote_asn' => (int) $data['remote_asn'],
            'remote_ip' => (string) $data['remote_ip'],
            'local_ip' => (string) $data['local_ip'],
            'status' => (string) ($data['status'] ?? 'established'),
            'prefixes_received' => (int) ($data['prefixes_received'] ?? 0),
            'created_at' => Carbon::now(),
        ]);

        return $peer;
    }

    /**
     * Ingest periodic bandwidth and session telemetry for BGP peer
     */
    public function recordTelemetry(string $peerId, int $inBps, int $outBps, int $uptimeSeconds = 3600, int $flaps = 0): BgpTelemetry
    {
        /** @var BgpPeer $peer */
        $peer = BgpPeer::withoutGlobalScopes()->findOrFail($peerId);

        /** @var BgpTelemetry $telemetry */
        $telemetry = BgpTelemetry::withoutGlobalScopes()->create([
            'peer_id' => $peer->peer_id,
            'tenant_id' => $peer->tenant_id,
            'in_bps' => $inBps,
            'out_bps' => $outBps,
            'uptime_seconds' => $uptimeSeconds,
            'flaps_count' => $flaps,
            'sampled_at' => Carbon::now(),
        ]);

        return $telemetry;
    }

    /**
     * Analyze local IXP vs Upstream IP Transit traffic distribution
     *
     * @return array<string, mixed>
     */
    public function analyzePeeringRatios(int $tenantId): array
    {
        $peers = BgpPeer::withoutGlobalScopes()
            ->with(['telemetry' => fn ($q) => $q->latest('sampled_at')->limit(1)])
            ->where('tenant_id', $tenantId)
            ->get();

        $ixpTrafficBps = 0;
        $transitTrafficBps = 0;
        $otherTrafficBps = 0;

        foreach ($peers as $peer) {
            /** @var BgpTelemetry|null $latest */
            $latest = $peer->telemetry->first();
            $bps = $latest ? ($latest->in_bps + $latest->out_bps) : 0;

            if ($peer->peer_type === 'ixp') {
                $ixpTrafficBps += $bps;
            } elseif ($peer->peer_type === 'transit') {
                $transitTrafficBps += $bps;
            } else {
                $otherTrafficBps += $bps;
            }
        }

        $totalBps = $ixpTrafficBps + $transitTrafficBps + $otherTrafficBps;
        $ixpRatio = $totalBps > 0 ? round(($ixpTrafficBps / $totalBps) * 100.0, 2) : 0.0;
        $transitRatio = $totalBps > 0 ? round(($transitTrafficBps / $totalBps) * 100.0, 2) : 0.0;

        return [
            'tenant_id' => $tenantId,
            'total_peers' => $peers->count(),
            'total_traffic_bps' => $totalBps,
            'ixp_traffic_bps' => $ixpTrafficBps,
            'transit_traffic_bps' => $transitTrafficBps,
            'ixp_ratio_pct' => $ixpRatio,
            'transit_ratio_pct' => $transitRatio,
            'cost_optimization_status' => $ixpRatio >= 60.0 ? 'optimal' : 'suboptimal_high_transit_cost',
        ];
    }
}
