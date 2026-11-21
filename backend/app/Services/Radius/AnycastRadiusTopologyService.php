<?php

declare(strict_types=1);

namespace App\Services\Radius;

use App\Models\AnycastHealthCheck;
use App\Models\AnycastNode;
use App\Services\Audit\BillingAuditService;
use Illuminate\Database\Eloquent\Collection;

/**
 * P2-9: Multi-Region Multi-Cluster RADIUS Anycast Topology Service
 */
class AnycastRadiusTopologyService
{
    public function __construct(
        protected BillingAuditService $auditService
    ) {}

    /**
     * Register a new Anycast AAA node in the regional topology.
     *
     * @param  array{
     *     tenant_id?: int,
     *     node_code: string,
     *     region: string,
     *     pop_name: string,
     *     vip_ipv4: string,
     *     physical_ip: string,
     *     bgp_asn?: int,
     *     bgp_community?: string,
     *     weight?: int
     * }  $data
     */
    public function registerNode(array $data): AnycastNode
    {
        return AnycastNode::create([
            'tenant_id' => $data['tenant_id'] ?? 1,
            'node_code' => $data['node_code'],
            'region' => $data['region'],
            'pop_name' => $data['pop_name'],
            'vip_ipv4' => $data['vip_ipv4'],
            'physical_ip' => $data['physical_ip'],
            'bgp_asn' => $data['bgp_asn'] ?? 65001,
            'bgp_community' => $data['bgp_community'] ?? '65001:100',
            'weight' => $data['weight'] ?? 100,
            'status' => 'active',
            'is_announced' => true,
            'last_heartbeat_at' => now(),
        ]);
    }

    /**
     * Ingest and evaluate periodic health check telemetry for an Anycast node.
     *
     * @param  array{
     *     auth_latency_ms: float,
     *     acct_latency_ms: float,
     *     replication_lag_seconds: float,
     *     bgp_session_up: bool,
     *     packet_loss_pct?: float,
     *     details?: array<string, mixed>
     * }  $metrics
     */
    public function recordHealthCheck(string $nodeCode, array $metrics): AnycastHealthCheck
    {
        $node = AnycastNode::where('node_code', $nodeCode)->firstOrFail();

        $authLatency = (float) $metrics['auth_latency_ms'];
        $acctLatency = (float) $metrics['acct_latency_ms'];
        $replLag = (float) $metrics['replication_lag_seconds'];
        $bgpUp = (bool) $metrics['bgp_session_up'];
        $packetLoss = (float) ($metrics['packet_loss_pct'] ?? 0.0);

        // Carrier SLA criteria: Auth <= 20ms, Acct <= 30ms, Repl lag <= 5s, BGP UP, Packet Loss <= 2%
        $isHealthy = ($authLatency <= 20.0)
            && ($acctLatency <= 30.0)
            && ($replLag <= 5.0)
            && $bgpUp
            && ($packetLoss <= 2.0);

        $healthCheck = AnycastHealthCheck::create([
            'node_id' => $node->id,
            'auth_latency_ms' => $authLatency,
            'acct_latency_ms' => $acctLatency,
            'replication_lag_seconds' => $replLag,
            'bgp_session_up' => $bgpUp,
            'packet_loss_pct' => $packetLoss,
            'is_healthy' => $isHealthy,
            'details' => $metrics['details'] ?? null,
            'checked_at' => now(),
        ]);

        // Evaluate automated BGP route withdrawal or restoration
        $newStatus = $isHealthy ? 'active' : 'degraded';
        $shouldWithdraw = (! $isHealthy && ($replLag > 10.0 || $authLatency > 50.0 || ! $bgpUp));

        if ($shouldWithdraw && $node->is_announced) {
            $node->update([
                'status' => 'draining',
                'is_announced' => false,
                'auth_latency_ms' => $authLatency,
                'replication_lag_seconds' => $replLag,
                'last_heartbeat_at' => now(),
            ]);

            $this->auditService->logEvent(
                'anycast.bgp_route_withdrawn',
                'anycast_node',
                $node->node_code,
                'topology_monitor',
                'health_evaluator',
                ['is_announced' => true, 'status' => 'active'],
                [
                    'is_announced' => false,
                    'status' => 'draining',
                    'auth_latency_ms' => $authLatency,
                    'replication_lag_seconds' => $replLag,
                    'bgp_session_up' => $bgpUp,
                ]
            );
        } else {
            $node->update([
                'status' => $newStatus,
                'auth_latency_ms' => $authLatency,
                'replication_lag_seconds' => $replLag,
                'last_heartbeat_at' => now(),
            ]);
        }

        return $healthCheck;
    }

    /**
     * Manually drain a node by withdrawing its BGP Anycast community announcement.
     */
    public function drainNode(string $nodeCode, string $reason): AnycastNode
    {
        $node = AnycastNode::where('node_code', $nodeCode)->firstOrFail();

        $node->update([
            'status' => 'draining',
            'is_announced' => false,
        ]);

        $this->auditService->logEvent(
            'anycast.manual_drain',
            'anycast_node',
            $node->node_code,
            'noc_operator',
            'admin',
            ['is_announced' => true],
            ['is_announced' => false, 'status' => 'draining', 'reason' => $reason]
        );

        return $node;
    }

    /**
     * Announce BGP route for a healthy Anycast node.
     */
    public function announceNode(string $nodeCode): AnycastNode
    {
        $node = AnycastNode::where('node_code', $nodeCode)->firstOrFail();

        $node->update([
            'status' => 'active',
            'is_announced' => true,
        ]);

        $this->auditService->logEvent(
            'anycast.route_announced',
            'anycast_node',
            $node->node_code,
            'noc_operator',
            'admin',
            ['is_announced' => false],
            ['is_announced' => true, 'status' => 'active']
        );

        return $node;
    }

    /**
     * Retrieve global Anycast topology status overview across all regions.
     *
     * @return array{
     *     total_nodes: int,
     *     active_announced: int,
     *     withdrawn_draining: int,
     *     average_auth_latency_ms: float,
     *     regional_breakdown: array<string, array{total: int, announced: int, avg_latency: float}>,
     *     nodes: Collection<int, AnycastNode>
     * }
     */
    public function getTopologyStatus(): array
    {
        $nodes = AnycastNode::all();

        $totalNodes = $nodes->count();
        $announced = $nodes->where('is_announced', true)->count();
        $draining = $totalNodes - $announced;
        $avgLatency = $totalNodes > 0 ? (float) $nodes->avg('auth_latency_ms') : 0.0;

        /** @var array<string, array{total: int, announced: int, avg_latency: float}> $regional */
        $regional = [];
        $grouped = $nodes->groupBy('region');

        foreach ($grouped as $regionName => $regionNodes) {
            $regTotal = $regionNodes->count();
            $regAnnounced = $regionNodes->where('is_announced', true)->count();
            $regAvg = $regTotal > 0 ? (float) $regionNodes->avg('auth_latency_ms') : 0.0;

            $regional[(string) $regionName] = [
                'total' => $regTotal,
                'announced' => $regAnnounced,
                'avg_latency' => round($regAvg, 2),
            ];
        }

        return [
            'total_nodes' => $totalNodes,
            'active_announced' => $announced,
            'withdrawn_draining' => $draining,
            'average_auth_latency_ms' => round($avgLatency, 2),
            'regional_breakdown' => $regional,
            'nodes' => $nodes,
        ];
    }
}
