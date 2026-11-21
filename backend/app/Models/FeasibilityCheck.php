<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-11: Fiber Feasibility Check Model
 */
class FeasibilityCheck extends Model
{
    use HasFactory;

    protected $table = 'feasibility_checks';

    protected $primaryKey = 'feasibility_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'feasibility_id',
        'tenant_id',
        'lead_id',
        'nearest_pop_or_fat',
        'distance_meters',
        'is_feasible',
        'max_bandwidth_mbps',
        'estimated_drop_fiber_meters',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'distance_meters' => 'integer',
            'is_feasible' => 'boolean',
            'max_bandwidth_mbps' => 'integer',
            'estimated_drop_fiber_meters' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id', 'lead_id');
    }
}
