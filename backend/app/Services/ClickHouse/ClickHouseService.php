<?php

declare(strict_types=1);

namespace App\Services\ClickHouse;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Release 2.0 (Epic 3): ClickHouse Carrier IPDR & High-Velocity Telemetry Service
 */
class ClickHouseService
{
    private string $host;

    private int $port;

    private string $database;

    private string $user;

    private string $password;

    public function __construct()
    {
        $this->host = env('CLICKHOUSE_HOST', 'clickhouse');
        $this->port = (int) env('CLICKHOUSE_PORT', 8123);
        $this->database = env('CLICKHOUSE_DB', 'isp_telemetry');
        $this->user = env('CLICKHOUSE_USER', 'default');
        $this->password = env('CLICKHOUSE_PASSWORD', '');
    }

    public function getBaseUrl(): string
    {
        return sprintf('http://%s:%d', $this->host, $this->port);
    }

    /**
     * Test connection to ClickHouse server
     */
    public function ping(): bool
    {
        try {
            $resp = Http::timeout(3)->get($this->getBaseUrl().'/ping');

            return $resp->successful() && trim($resp->body()) === 'Ok.';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Execute arbitrary ClickHouse query
     */
    public function executeQuery(string $sql, array $params = []): array
    {
        $url = $this->getBaseUrl().'/';
        $fullSql = $sql;
        if (! empty($params)) {
            foreach ($params as $k => $v) {
                $fullSql = str_replace(':'.$k, is_numeric($v) ? (string) $v : "'".addslashes((string) $v)."'", $fullSql);
            }
        }

        try {
            $resp = Http::timeout(10)
                ->withHeaders([
                    'X-ClickHouse-User' => $this->user,
                    'X-ClickHouse-Key' => $this->password,
                ])
                ->withBody($fullSql, 'text/plain')
                ->post($url.'?database='.$this->database.'&default_format=JSON');

            if (! $resp->successful()) {
                Log::error('ClickHouse Query Error: '.$resp->body());

                return ['error' => $resp->body(), 'status' => $resp->status()];
            }

            return $resp->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('ClickHouse Exception: '.$e->getMessage());

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Bulk Ingest BTRC IPDR Records via JSONEachRow
     */
    public function insertIpdrBatch(array $records): bool
    {
        if (empty($records)) {
            return true;
        }

        $lines = [];
        foreach ($records as $r) {
            $lines[] = json_encode([
                'event_time' => $r['event_time'] ?? now()->format('Y-m-d H:i:s'),
                'username' => $r['username'],
                'tenant_id' => $r['tenant_id'] ?? 1,
                'framed_ip' => $r['framed_ip'],
                'nas_ip' => $r['nas_ip'] ?? '10.10.0.1',
                'public_nat_ip' => $r['public_nat_ip'] ?? $r['framed_ip'],
                'public_port_start' => (int) ($r['public_port_start'] ?? 1024),
                'public_port_end' => (int) ($r['public_port_end'] ?? 2047),
                'input_octets' => (int) ($r['input_octets'] ?? 0),
                'output_octets' => (int) ($r['output_octets'] ?? 0),
                'acct_session_id' => $r['acct_session_id'] ?? '',
                'calling_station_id' => $r['calling_station_id'] ?? '',
                'mac_address' => $r['mac_address'] ?? '',
                'event_type' => $r['event_type'] ?? 'INTERIM',
            ]);
        }

        $body = implode("\n", $lines);
        $url = $this->getBaseUrl().'/?database='.$this->database.'&query='.urlencode('INSERT INTO btrc_ipdr_records FORMAT JSONEachRow');

        try {
            $resp = Http::timeout(15)
                ->withBody($body, 'application/json')
                ->withHeaders([
                    'X-ClickHouse-User' => $this->user,
                    'X-ClickHouse-Key' => $this->password,
                ])
                ->post($url);

            return $resp->successful();
        } catch (\Throwable $e) {
            Log::error('ClickHouse IPDR Ingestion Failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Sub-second forensic search for BTRC IPDR records
     */
    public function searchIpdr(
        ?string $ip = null,
        ?string $username = null,
        ?string $startTime = null,
        ?string $endTime = null,
        int $limit = 50
    ): array {
        $conditions = ['1=1'];
        $params = [];

        if ($ip) {
            $conditions[] = 'framed_ip = :ip';
            $params['ip'] = $ip;
        }

        if ($username) {
            $conditions[] = 'username = :username';
            $params['username'] = $username;
        }

        if ($startTime) {
            $conditions[] = 'event_time >= :start';
            $params['start'] = $startTime;
        }

        if ($endTime) {
            $conditions[] = 'event_time <= :end';
            $params['end'] = $endTime;
        }

        $sql = sprintf(
            'SELECT event_time, username, framed_ip, nas_ip, public_nat_ip, public_port_start, public_port_end, input_octets, output_octets, acct_session_id, calling_station_id '.
            'FROM btrc_ipdr_records WHERE %s ORDER BY event_time DESC LIMIT %d FORMAT JSON',
            implode(' AND ', $conditions),
            $limit
        );

        return $this->executeQuery($sql, $params);
    }
}
