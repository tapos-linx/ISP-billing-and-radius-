<?php
// File: app/Enums/InvoiceStatus.php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum InvoiceStatus: string implements HasLabel, HasColor, HasIcon
{
    case UNPAID = 'unpaid';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    public function getLabel(): string
    {
        return match ($this) {
            self::UNPAID => 'Unpaid',
            self::PAID => 'Paid',
            self::CANCELLED => 'Cancelled',
            self::REFUNDED => 'Refunded',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::UNPAID => 'warning',
            self::PAID => 'success',
            self::CANCELLED => 'gray',
            self::REFUNDED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::UNPAID => 'heroicon-m-exclamation-circle',
            self::PAID => 'heroicon-m-check-badge',
            self::CANCELLED => 'heroicon-m-x-circle',
            self::REFUNDED => 'heroicon-m-arrow-uturn-left',
        };
    }
}
