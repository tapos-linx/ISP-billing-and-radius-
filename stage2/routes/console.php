<?php

declare(strict_types=1);

use App\Console\Commands\ProcessExpiredSubscribers;
use Illuminate\Support\Facades\Schedule;

Schedule::command(ProcessExpiredSubscribers::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();
