<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SubscriberStatus;
use App\Jobs\DisconnectSubscriberJob;
use App\Models\Subscriber;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * CP-03.M4: Scheduled Expiry Scanner Command (`isp:process-expired-subscribers`).
 *
 * Scheduled via `Schedule::command('isp:process-expired-subscribers')->everyMinute()->withoutOverlapping()`
 * in `routes/console.php`. Scans active subscribers whose expiry timestamp has passed
 * in memory-safe 500-row chunks and dispatches `DisconnectSubscriberJob` to Laravel Horizon.
 */
#[AsCommand(
    name: 'isp:process-expired-subscribers',
    description: 'Find active subscribers whose expiry_date / expires_at has passed and dispatch DisconnectSubscriberJob.'
)]
class ProcessExpiredSubscribers extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'isp:process-expired-subscribers
                            {--chunk=500 : Number of subscribers to process per database batch}
                            {--dry-run : List expired subscribers without dispatching Horizon jobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find active subscribers whose expiry_date / expires_at has passed and dispatch DisconnectSubscriberJob.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $chunkSize = max(50, (int) $this->option('chunk'));
        $isDryRun = (bool) $this->option('dry-run');

        // Support both `expiry_date` and `expires_at` column naming conventions transparently
        $expiryColumn = Schema::hasColumn('subscribers', 'expiry_date')
            ? 'expiry_date'
            : 'expires_at';

        $dispatchedCount = 0;

        Subscriber::query()
            ->where('status', SubscriberStatus::Active)
            ->whereNotNull($expiryColumn)
            ->where($expiryColumn, '<=', $now)
            ->chunkById($chunkSize, function (Collection $expiredSubscribers) use (&$dispatchedCount, $isDryRun): void {
                /** @var Subscriber $subscriber */
                foreach ($expiredSubscribers as $subscriber) {
                    if (! $isDryRun) {
                        DisconnectSubscriberJob::dispatch($subscriber);
                    }

                    $dispatchedCount++;
                }
            });

        if ($isDryRun) {
            $this->info("[DRY-RUN] Found {$dispatchedCount} expired active subscriber(s) as of {$now->toDateTimeString()}.");
        } else {
            $this->info("Dispatched {$dispatchedCount} DisconnectSubscriberJob(s) to Horizon queue [coa-disconnects] as of {$now->toDateTimeString()}.");
        }

        return self::SUCCESS;
    }
}
