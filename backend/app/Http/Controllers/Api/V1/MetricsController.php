<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Metrics\PrometheusMetricsService;
use Illuminate\Http\Response;

/**
 * Phase 4: Prometheus Metrics HTTP Scraping Controller
 * Exposes /api/v1/metrics formatted according to Prometheus exposition format 0.0.4.
 */
class MetricsController extends Controller
{
    public function __construct(
        private readonly PrometheusMetricsService $metricsService
    ) {}

    public function metrics(): Response
    {
        $content = $this->metricsService->toPrometheusText();

        return response($content, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
