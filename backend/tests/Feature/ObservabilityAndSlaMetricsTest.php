<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Metrics\PrometheusMetricsService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Phase 4: Observability & Carrier SLA Metrics Feature Tests
 * Tests Prometheus histogram/counter recording, SLA threshold breach tracking:
 *  - RADIUS Access-Accept (< 10ms SLA)
 *  - RFC 5176 CoA Kick (< 500ms SLA)
 *  - BSS Payment-to-Unblock (< 3.0s SLA)
 *  - HTTP /api/v1/metrics Prometheus exposition format
 */
class ObservabilityAndSlaMetricsTest extends TestCase
{
    private PrometheusMetricsService $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = app(PrometheusMetricsService::class);
    }

    public function test_access_accept_latency_recording_and_sla_breach(): void
    {
        // 1. Record normal fast Access-Accept (4ms, well under 10ms SLA)
        $this->metrics->recordAccessAcceptLatency(0.004, true);

        $text = $this->metrics->toPrometheusText();
        $this->assertStringContainsString('radius_access_requests_total{status="accept"}', $text);
        $this->assertStringContainsString('radius_probe_healthy 1', $text);

        // 2. Record slow Access-Accept (18ms, breaches 10ms SLA)
        $this->metrics->recordAccessAcceptLatency(0.018, true);

        $textAfter = $this->metrics->toPrometheusText();
        $this->assertStringContainsString('bss_sla_breaches_total{sla_type="access_accept"}', $textAfter);
    }

    public function test_coa_kick_latency_recording_and_sla_breach(): void
    {
        // 1. Record normal CoA kick (85ms, under 500ms SLA)
        $this->metrics->recordCoaLatency(0.085, 'disconnect', true);

        $text = $this->metrics->toPrometheusText();
        $this->assertStringContainsString('radius_coa_requests_total{action="disconnect",status="success"}', $text);
        $this->assertStringContainsString('radius_coa_latency_seconds_bucket', $text);

        // 2. Record slow CoA kick (620ms, breaches 500ms SLA)
        $this->metrics->recordCoaLatency(0.620, 'coa', true);

        $textAfter = $this->metrics->toPrometheusText();
        $this->assertStringContainsString('bss_sla_breaches_total{sla_type="coa_kick"}', $textAfter);
    }

    public function test_payment_unblock_latency_recording_and_sla_breach(): void
    {
        // 1. Record normal payment unblock (750ms, under 3.0s SLA)
        $this->metrics->recordPaymentUnblockLatency(0.750, 'bkash', 'accepted');

        $text = $this->metrics->toPrometheusText();
        $this->assertStringContainsString('bss_payments_processed_total{gateway="bkash",status="accepted"}', $text);
        $this->assertStringContainsString('bss_payment_unblock_latency_seconds_bucket', $text);

        // 2. Record slow payment unblock (3.80s, breaches 3.0s SLA)
        $this->metrics->recordPaymentUnblockLatency(3.800, 'nagad', 'accepted');

        $textAfter = $this->metrics->toPrometheusText();
        $this->assertStringContainsString('bss_sla_breaches_total{sla_type="payment_unblock"}', $textAfter);
    }

    public function test_prometheus_metrics_http_endpoint(): void
    {
        $response = $this->get('/api/v1/metrics');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('version=0.0.4', $response->headers->get('Content-Type'));

        $body = $response->getContent();
        $this->assertStringContainsString('# HELP radius_access_accept_latency_seconds', $body);
        $this->assertStringContainsString('# TYPE radius_access_accept_latency_seconds histogram', $body);
        $this->assertStringContainsString('# HELP radius_coa_latency_seconds', $body);
        $this->assertStringContainsString('# TYPE radius_coa_latency_seconds histogram', $body);
        $this->assertStringContainsString('# HELP bss_payment_unblock_latency_seconds', $body);
        $this->assertStringContainsString('# TYPE bss_payment_unblock_latency_seconds histogram', $body);
        $this->assertStringContainsString('# HELP bss_sla_breaches_total', $body);
        $this->assertStringContainsString('# TYPE bss_sla_breaches_total counter', $body);
    }

    public function test_probe_aaa_artisan_command_executes(): void
    {
        $exitCode = Artisan::call('metrics:probe-aaa', [
            '--iterations' => 1,
            '--host' => 'freeradius_aaa',
            '--port' => 1812,
            '--username' => 'e2e_live_user',
            '--password' => 'E2eLivePass@2026',
        ]);

        $this->assertEquals(0, $exitCode);
    }
}
