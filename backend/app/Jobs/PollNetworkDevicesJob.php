<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Monitoring\DevicePollingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * P0-11: Scheduled Network Device Polling Worker Job
 */
class PollNetworkDevicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DevicePollingService $pollingService): array
    {
        Log::info('PollNetworkDevicesJob starting network topology polling cycle...');
        $summary = $pollingService->pollAllDevices();
        Log::info('PollNetworkDevicesJob finished', $summary);

        return $summary;
    }
}
