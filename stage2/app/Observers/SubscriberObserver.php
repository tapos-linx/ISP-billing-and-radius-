<?php
// File: app/Observers/SubscriberObserver.php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SendRadiusCoaDisconnectJob;
use App\Models\Subscriber;
use App\Services\RadiusSyncService;

class SubscriberObserver
{
    public function __construct(
        private readonly RadiusSyncService $radiusSyncService,
    ) {}

    /**
     * Handle the Subscriber "created" event.
     */
    public function created(Subscriber $subscriber): void
    {
        $this->radiusSyncService->syncSubscriber($subscriber);
    }

    /**
     * Handle the Subscriber "updated" event.
     */
    public function updated(Subscriber $subscriber): void
    {
        $previousUsername = $subscriber->wasChanged('username')
            ? (string) $subscriber->getOriginal('username')
            : null;

        $this->radiusSyncService->syncSubscriber($subscriber, $previousUsername);

        // Dispatch RFC 3576 CoA / PoD session kick if AAA-relevant attributes changed
        if ($subscriber->wasChanged(['status', 'tariff_id', 'pppoe_password', 'static_ipv4', 'username'])) {
            if (class_exists(SendRadiusCoaDisconnectJob::class)) {
                SendRadiusCoaDisconnectJob::dispatch($subscriber->id)->onQueue('high');
            }
        }
    }

    /**
     * Handle the Subscriber "deleted" event.
     */
    public function deleted(Subscriber $subscriber): void
    {
        $this->radiusSyncService->deleteSubscriber($subscriber->username);

        if (class_exists(SendRadiusCoaDisconnectJob::class)) {
            SendRadiusCoaDisconnectJob::dispatch($subscriber->id)->onQueue('high');
        }
    }
}
