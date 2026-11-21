<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ClickHouse\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Release 2.0 (Epic 3): BTRC IPDR & ClickHouse Telemetry Controller
 */
class IpdrController extends Controller
{
    public function __construct(
        protected ClickHouseService $clickHouseService
    ) {}

    public function status(): JsonResponse
    {
        $ping = $this->clickHouseService->ping();

        return response()->json([
            'status' => $ping ? 'connected' : 'disconnected',
            'engine' => 'ClickHouse MergeTree (730-day TTL)',
            'base_url' => $this->clickHouseService->getBaseUrl(),
        ], $ping ? 200 : 503);
    }

    public function ingest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'records' => 'required|array|min:1',
            'records.*.username' => 'required|string',
            'records.*.framed_ip' => 'required|string',
            'records.*.event_time' => 'nullable|string',
            'records.*.public_nat_ip' => 'nullable|string',
            'records.*.public_port_start' => 'nullable|integer',
            'records.*.public_port_end' => 'nullable|integer',
            'records.*.input_octets' => 'nullable|integer',
            'records.*.output_octets' => 'nullable|integer',
            'records.*.acct_session_id' => 'nullable|string',
            'records.*.calling_station_id' => 'nullable|string',
        ]);

        $success = $this->clickHouseService->insertIpdrBatch($validated['records']);

        if (! $success) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to ingest records into ClickHouse cluster.',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => sprintf('Successfully ingested %d IPDR records into ClickHouse.', count($validated['records'])),
            'count' => count($validated['records']),
        ], 202);
    }

    public function search(Request $request): JsonResponse
    {
        $ip = $request->query('ip');
        $username = $request->query('username');
        $startTime = $request->query('start_time');
        $endTime = $request->query('end_time');
        $limit = (int) $request->query('limit', 50);

        $results = $this->clickHouseService->searchIpdr($ip, $username, $startTime, $endTime, $limit);

        return response()->json([
            'status' => 'success',
            'query' => [
                'ip' => $ip,
                'username' => $username,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ],
            'data' => $results['data'] ?? [],
            'rows' => $results['rows'] ?? 0,
            'statistics' => $results['statistics'] ?? [],
        ]);
    }
}
