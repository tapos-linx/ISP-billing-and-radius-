<?php
// File: app/Observers/TariffObserver.php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Tariff;
use App\Services\Radius\RadiusSyncService;

class TariffObserver
{
    public function __construct(
        private readonly RadiusSyncService $radiusSyncService,
    ) {}

    public function updated(Tariff $tariff): void
    {
        if ($tariff->wasChanged(['mikrotik_rate_limit', 'framed_pool', 'walled_garden_pool'])) {
            $this->radiusSyncService->syncTariffSubscribers($tariff);
        }
    }
}
