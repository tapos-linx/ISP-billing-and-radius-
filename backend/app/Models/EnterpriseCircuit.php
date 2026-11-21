<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P2-1: Enterprise Dedicated Circuit Model
 */
class EnterpriseCircuit extends Model
{
    use HasFactory;

    protected $table = 'enterprise_circuits';

    protected $primaryKey = 'circuit_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'circuit_id',
        'tenant_id',
        'subscriber_id',
        'circuit_ref',
        'committed_bandwidth_mbps',
        'sla_target_availability_pct',
        'max_latency_ms',
        'max_jitter_ms',
        'max_packet_loss_pct',
        'monthly_rate_bdt',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'committed_bandwidth_mbps' => 'integer',
            'sla_target_availability_pct' => 'float',
            'max_latency_ms' => 'integer',
            'max_jitter_ms' => 'integer',
            'max_packet_loss_pct' => 'float',
            'monthly_rate_bdt' => 'float',
            'created_at' => 'datetime',
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

    public function breaches(): HasMany
    {
        return $this->hasMany(SlaBreach::class, 'circuit_id', 'circuit_id');
    }

    public function samples(): HasMany
    {
        return $this->hasMany(BandwidthSample95th::class, 'circuit_id', 'circuit_id');
    }
}
