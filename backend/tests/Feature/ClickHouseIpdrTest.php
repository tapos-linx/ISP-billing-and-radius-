<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Release 2.0 (Epic 3): ClickHouse BTRC 2-Year IPDR Telemetry Tests
 */
class ClickHouseIpdrTest extends TestCase
{
    public function test_clickhouse_status_endpoint(): void
    {
        $response = $this->getJson('/api/v1/ipdr/status');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('engine', 'ClickHouse MergeTree (730-day TTL)');
    }

    public function test_can_ingest_btrc_ipdr_batch(): void
    {
        $payload = [
            'records' => [
                [
                    'username' => 'btrc_test_user_01',
                    'framed_ip' => '100.64.10.55',
                    'event_time' => now()->format('Y-m-d H:i:s'),
                    'public_nat_ip' => '103.145.118.10',
                    'public_port_start' => 2000,
                    'public_port_end' => 2999,
                    'input_octets' => 52428800,
                    'output_octets' => 104857600,
                    'acct_session_id' => 'SESS_CH_001',
                    'calling_station_id' => 'E4:8D:8C:11:22:33',
                ],
                [
                    'username' => 'btrc_test_user_02',
                    'framed_ip' => '100.64.10.56',
                    'event_time' => now()->format('Y-m-d H:i:s'),
                    'public_nat_ip' => '103.145.118.10',
                    'public_port_start' => 3000,
                    'public_port_end' => 3999,
                    'input_octets' => 1024000,
                    'output_octets' => 2048000,
                    'acct_session_id' => 'SESS_CH_002',
                    'calling_station_id' => 'E4:8D:8C:44:55:66',
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/ipdr/ingest', $payload);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('count', 2);
    }

    public function test_sub_second_forensic_search_by_framed_ip(): void
    {
        // First ingest record
        $this->postJson('/api/v1/ipdr/ingest', [
            'records' => [
                [
                    'username' => 'forensic_target_sub',
                    'framed_ip' => '100.64.99.100',
                    'event_time' => now()->format('Y-m-d H:i:s'),
                    'public_nat_ip' => '103.145.118.50',
                    'public_port_start' => 5000,
                    'public_port_end' => 5999,
                    'input_octets' => 1000000,
                    'output_octets' => 2000000,
                    'acct_session_id' => 'SESS_FORENSIC_99',
                    'calling_station_id' => 'AA:11:22:33:44:55',
                ],
            ],
        ]);

        // Search forensic record
        $response = $this->getJson('/api/v1/ipdr/search?ip=100.64.99.100');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $rows = $response->json('data');
        $this->assertNotEmpty($rows);
        $this->assertEquals('100.64.99.100', $rows[0]['framed_ip']);
        $this->assertEquals('forensic_target_sub', $rows[0]['username']);
    }
}
