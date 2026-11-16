<?php
// File: app/Enums/SubscriberStatus.php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SubscriberStatus: string implements HasLabel, HasColor, HasIcon
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case SUSPENDED = 'suspended';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active (Online SLA)',
            self::EXPIRED => 'Expired (Walled Garden)',
            self::SUSPENDED => 'Suspended (Auth Reject)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::EXPIRED => 'warning',
            self::SUSPENDED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::ACTIVE => 'heroicon-m-check-circle',
            self::EXPIRED => 'heroicon-m-clock',
            self::SUSPENDED => 'heroicon-m-no-symbol',
        };
    }
}
