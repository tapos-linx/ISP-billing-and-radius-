<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P0-11: Network Device Monitoring Model
 */
class NetworkDevice extends Model
{
    use HasFactory;

    protected $table = 'network_devices';

    protected $primaryKey = 'device_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'tenant_id',
        'name',
        'device_type',
        'ip_address',
        'snmp_community',
        'snmp_version',
        'poll_interval_sec',
        'status',
        'last_polled_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'poll_interval_sec' => 'integer',
            'last_polled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(DeviceMetric::class, 'device_id', 'device_id');
    }
}
