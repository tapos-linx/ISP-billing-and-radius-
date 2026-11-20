<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 6: Multi-Tenant Organization Model
 */
class Tenant extends Model
{
    use HasFactory;

    protected $table = 'tenants';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'ip_pool_cidr',
        'bandwidth_quota_mbps',
        'contact_email',
        'contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'bandwidth_quota_mbps' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(Subscriber::class, 'tenant_id');
    }

    public function ipPools(): HasMany
    {
        return $this->hasMany(IpPool::class, 'tenant_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'tenant_id');
    }

    public function nasDevices(): HasMany
    {
        return $this->hasMany(Nas::class, 'tenant_id');
    }
}
