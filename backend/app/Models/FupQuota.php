<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-1: Fair Usage Policy (FUP) Quota & Throttling Model
 */
class FupQuota extends Model
{
    use HasFactory;

    protected $table = 'fup_quotas';

    protected $primaryKey = 'quota_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'quota_id',
        'tenant_id',
        'subscriber_id',
        'cycle_start',
        'cycle_end',
        'data_limit_bytes',
        'used_bytes',
        'rollover_bytes',
        'throttle_rate_limit',
        'is_throttled',
        'throttled_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'data_limit_bytes' => 'integer',
            'used_bytes' => 'integer',
            'rollover_bytes' => 'integer',
            'is_throttled' => 'boolean',
            'cycle_start' => 'datetime',
            'cycle_end' => 'datetime',
            'throttled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
    }
}
