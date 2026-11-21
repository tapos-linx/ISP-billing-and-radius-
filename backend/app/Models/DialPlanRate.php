<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * P2-12: E.164 Dial-Plan Destination Rating Model
 */
class DialPlanRate extends Model
{
    use HasFactory;

    protected $table = 'dial_plan_rates';

    protected $primaryKey = 'rate_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'destination_prefix',
        'destination_name',
        'rate_per_minute_bdt',
        'pulse_seconds',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate_id' => 'integer',
            'tenant_id' => 'integer',
            'rate_per_minute_bdt' => 'float',
            'pulse_seconds' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
