<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * P2-10: Disaster Recovery Failover Drill Record
 */
class DrFailoverDrill extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'dr_failover_drills';

    protected $fillable = [
        'tenant_id',
        'drill_name',
        'drill_type',
        'initiated_by',
        'primary_region',
        'secondary_region',
        'status',
        'rto_seconds',
        'rpo_seconds',
        'sentinel_failover_verified',
        'replica_promotion_verified',
        's3_wal_restored',
        'anycast_diverted',
        'audit_hash',
        'drill_log',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'rto_seconds' => 'float',
            'rpo_seconds' => 'float',
            'sentinel_failover_verified' => 'boolean',
            'replica_promotion_verified' => 'boolean',
            's3_wal_restored' => 'boolean',
            'anycast_diverted' => 'boolean',
            'drill_log' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
