<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6: Subnet & IP Pool Model Allocated per Reseller / Tenant
 */
class IpPool extends Model
{
    use HasFactory;

    protected $table = 'ip_pools';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'pool_name',
        'subnet_cidr',
        'gateway_ip',
        'range_start',
        'range_end',
        'total_ips',
        'allocated_ips',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'total_ips' => 'integer',
            'allocated_ips' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
