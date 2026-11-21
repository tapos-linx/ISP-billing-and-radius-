<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-2: BGP Traffic & Session Telemetry Model
 */
class BgpTelemetry extends Model
{
    use HasFactory;

    protected $table = 'bgp_telemetry';

    protected $primaryKey = 'telemetry_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'peer_id',
        'tenant_id',
        'in_bps',
        'out_bps',
        'uptime_seconds',
        'flaps_count',
        'sampled_at',
    ];

    protected function casts(): array
    {
        return [
            'telemetry_id' => 'integer',
            'tenant_id' => 'integer',
            'in_bps' => 'integer',
            'out_bps' => 'integer',
            'uptime_seconds' => 'integer',
            'flaps_count' => 'integer',
            'sampled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(BgpPeer::class, 'peer_id', 'peer_id');
    }
}
