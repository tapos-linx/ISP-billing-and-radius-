<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-5: Wholesale L2TP LAC/LNS Tunnel Model
 */
class L2tpTunnel extends Model
{
    use HasFactory;

    protected $table = 'l2tp_tunnels';

    protected $primaryKey = 'tunnel_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'tunnel_id',
        'carrier_id',
        'tenant_id',
        'tunnel_name',
        'local_endpoint_ip',
        'remote_endpoint_ip',
        'shared_secret',
        'committed_mbps',
        'active_sessions',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'committed_mbps' => 'integer',
            'active_sessions' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(WholesaleCarrier::class, 'carrier_id', 'carrier_id');
    }
}
