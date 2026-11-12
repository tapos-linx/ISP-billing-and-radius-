<?php
// File: app/Models/Tariff.php

declare(strict_types=1);

namespace App\Models;

use App\Observers\TariffObserver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $download_mbps
 * @property int $upload_mbps
 * @property string $mikrotik_rate_limit
 * @property string $framed_pool
 * @property string $walled_garden_pool
 * @property string $monthly_price
 * @property int $validity_days
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Subscriber> $subscribers
 */
#[ObservedBy([TariffObserver::class])]
class Tariff extends Model
{
    use HasFactory;

    protected $table = 'tariffs';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'code',
        'download_mbps',
        'upload_mbps',
        'mikrotik_rate_limit',
        'framed_pool',
        'walled_garden_pool',
        'monthly_price',
        'validity_days',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'download_mbps' => 'integer',
            'upload_mbps'   => 'integer',
            'monthly_price' => 'decimal:2',
            'validity_days' => 'integer',
            'is_active'     => 'boolean',
            'created_at'    => 'immutable_datetime',
            'updated_at'    => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Subscriber, $this>
     */
    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class, 'tariff_id');
    }
}
