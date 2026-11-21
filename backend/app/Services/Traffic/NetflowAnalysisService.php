<?php

declare(strict_types=1);

namespace App\Services\Traffic;

use App\Models\NetflowRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * P1-9: NetFlow v5/v9 Flow Ingestion, Categorization & Usage Analysis Service
 */
class NetflowAnalysisService
{
    /**
     * Ingest raw flow telemetry batch and categorize protocol & application type
     *
     * @param array<int, array{
     *   tenant_id: int,
     *   subscriber_id?: string,
     *   src_ip: string,
     *   dst_ip: string,
     *   protocol: string,
     *   src_port: int,
     *   dst_port: int,
     *   bytes: int,
     *   packets: int
     * }> $flows
     */
    public function ingestFlows(array $flows): int
    {
        $now = Carbon::now();
        $records = [];

        foreach ($flows as $flow) {
            $category = $this->classifyTraffic(
                srcIp: $flow['src_ip'],
                dstIp: $flow['dst_ip'],
                srcPort: $flow['src_port'],
                dstPort: $flow['dst_port'],
                protocol: $flow['protocol']
            );

            $records[] = [
                'tenant_id' => $flow['tenant_id'],
                'subscriber_id' => $flow['subscriber_id'] ?? null,
                'src_ip' => $flow['src_ip'],
                'dst_ip' => $flow['dst_ip'],
                'protocol' => strtoupper($flow['protocol']),
                'src_port' => $flow['src_port'],
                'dst_port' => $flow['dst_port'],
                'bytes' => $flow['bytes'],
                'packets' => $flow['packets'],
                'category' => $category,
                'captured_at' => $now,
            ];
        }

        NetflowRecord::withoutGlobalScopes()->insert($records);

        return count($records);
    }

    /**
     * Get categorized traffic breakdown for a subscriber or network-wide
     */
    public function getTrafficBreakdown(?string $subscriberId = null, int $hours = 24): array
    {
        $since = Carbon::now()->subHours($hours);

        $query = DB::table('netflow_records')
            ->where('captured_at', '>=', $since)
            ->select('category')
            ->selectRaw('SUM(bytes) as total_bytes, SUM(packets) as total_packets, COUNT(*) as flow_count')
            ->groupBy('category');

        if ($subscriberId !== null) {
            $query->where('subscriber_id', $subscriberId);
        }

        $results = $query->get();
        $totalBytes = $results->sum('total_bytes');

        return [
            'subscriber_id' => $subscriberId,
            'window_hours' => $hours,
            'total_bytes' => (int) $totalBytes,
            'categories' => $results->map(function ($row) use ($totalBytes) {
                return [
                    'category' => $row->category,
                    'bytes' => (int) $row->total_bytes,
                    'packets' => (int) $row->total_packets,
                    'percentage' => $totalBytes > 0 ? round(((int) $row->total_bytes / $totalBytes) * 100, 2) : 0,
                ];
            })->toArray(),
        ];
    }

    protected function classifyTraffic(string $srcIp, string $dstIp, int $srcPort, int $dstPort, string $protocol): string
    {
        // 1. Check BDIX local routing prefixes (e.g. 103.x, 102.x local IXPs)
        if (str_starts_with($dstIp, '103.100.') || str_starts_with($srcIp, '103.100.')) {
            return 'bdix';
        }

        // 2. Gaming common UDP ports (CS:GO, Valorant, Dota 2: 27015-27030, 7000-8000)
        if (strtoupper($protocol) === 'UDP' && (($dstPort >= 27015 && $dstPort <= 27030) || ($dstPort >= 7000 && $dstPort <= 8000))) {
            return 'gaming';
        }

        // 3. YouTube / Google Video CDN (port 443 with specific prefixes or QUIC port 443 UDP)
        if ($dstPort === 443 && str_starts_with($dstIp, '142.250.')) {
            return 'youtube';
        }

        // 4. Meta / Facebook (157.240.x.x)
        if ($dstPort === 443 && str_starts_with($dstIp, '157.240.')) {
            return 'facebook';
        }

        if ($dstPort === 80 || $dstPort === 443 || $dstPort === 8080) {
            return 'web';
        }

        return 'general';
    }
}
