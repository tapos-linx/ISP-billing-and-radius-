<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P2-9: Multi-Region RADIUS Anycast Node Model
 */
class AnycastNode extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'anycast_nodes';

    protected $fillable = [
        'tenant_id',
        'node_code',
        'region',
        'pop_name',
        'vip_ipv4',
        'physical_ip',
        'bgp_asn',
        'bgp_community',
        'weight',
        'status',
        'is_announced',
        'auth_latency_ms',
        'replication_lag_seconds',
        'last_heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'bgp_asn' => 'integer',
            'weight' => 'integer',
            'is_announced' => 'boolean',
            'auth_latency_ms' => 'float',
            'replication_lag_seconds' => 'float',
            'last_heartbeat_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * @return HasMany<AnycastHealthCheck, $this>
     */
    public function healthChecks(): HasMany
    {
        return $this->hasMany(AnycastHealthCheck::class, 'node_id', 'id');
    }
}
