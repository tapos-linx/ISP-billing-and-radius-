<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * P2-13: AI Capacity & Bandwidth Exhaustion Forecast Model
 */
class CapacityForecast extends Model
{
    use HasFactory;

    protected $table = 'capacity_forecasts';

    protected $primaryKey = 'forecast_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'forecast_id',
        'tenant_id',
        'resource_type',
        'resource_id',
        'current_utilization_pct',
        'daily_growth_rate_pct',
        'days_to_exhaustion',
        'recommended_action',
        'forecasted_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'current_utilization_pct' => 'float',
            'daily_growth_rate_pct' => 'float',
            'days_to_exhaustion' => 'integer',
            'forecasted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
