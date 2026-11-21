<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P0-11: Network Device Telemetry Metric Point Model
 */
class DeviceMetric extends Model
{
    use HasFactory;

    protected $table = 'device_metrics';

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'tenant_id',
        'icmp_latency_ms',
        'packet_loss_percent',
        'cpu_utilization_percent',
        'ram_utilization_percent',
        'uptime_seconds',
        'polled_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'icmp_latency_ms' => 'float',
            'packet_loss_percent' => 'float',
            'cpu_utilization_percent' => 'float',
            'ram_utilization_percent' => 'float',
            'uptime_seconds' => 'integer',
            'polled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'device_id', 'device_id');
    }
}
