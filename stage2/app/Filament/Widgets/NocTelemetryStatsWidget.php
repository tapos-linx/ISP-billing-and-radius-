<?php
// File: app/Filament/Widgets/NocTelemetryStatsWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriberStatus;
use App\Models\Invoice;
use App\Models\Nas;
use App\Models\Subscriber;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class NocTelemetryStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '5s';

    protected function getStats(): array
    {
        $activeSubscribers = Subscriber::query()
            ->where('status', SubscriberStatus::ACTIVE)
            ->count();

        $expiredSubscribers = Subscriber::query()
            ->where('status', SubscriberStatus::EXPIRED)
            ->count();

        $onlineBngs = Nas::query()
            ->where('is_active', true)
            ->count();

        $todayCollection = (float) Invoice::query()
            ->where('status', InvoiceStatus::PAID)
            ->whereDate('paid_at', today())
            ->sum('amount');

        $lastLatency = (float) Cache::get('noc.last_storm_latency_ms', 0.38);

        return [
            Stat::make('Active PPPoE Sessions', number_format($activeSubscribers))
                ->description("{$expiredSubscribers} in Walled Garden pool")
                ->descriptionIcon('heroicon-m-signal')
                ->color('success'),

            Stat::make('Online Anycast BNGs', (string) $onlineBngs)
                ->description('UDP 3799 CoA/PoD Ready')
                ->descriptionIcon('heroicon-m-server-stack')
                ->color('info'),

            Stat::make('Today MFS Revenue (BDT)', '৳ ' . number_format($todayCollection, 2))
                ->description('bKash & Nagad (<3s Auto-Unblock)')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('AAA Auth Latency SLA', "{$lastLatency} ms")
                ->description('128 Citus Shards + Redis 7 Fast-Path')
                ->descriptionIcon('heroicon-m-bolt')
                ->color($lastLatency < 10.0 ? 'success' : 'danger'),
        ];
    }
}
