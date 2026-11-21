<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * P1-2: Scheduled Time-of-Day Boost & Contention Ratio Profile Model
 */
class TimeOfDayProfile extends Model
{
    use HasFactory;

    protected $table = 'time_of_day_profiles';

    protected $primaryKey = 'profile_id';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'name',
        'start_time',
        'end_time',
        'boost_factor',
        'boost_rate_limit',
        'contention_ratio',
        'days_of_week',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'boost_factor' => 'float',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
