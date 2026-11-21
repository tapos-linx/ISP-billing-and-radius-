<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P2-2: BGP Peering Session Model
 */
class BgpPeer extends Model
{
    use HasFactory;

    protected $table = 'bgp_peers';

    protected $primaryKey = 'peer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'peer_id',
        'tenant_id',
        'peer_name',
        'peer_type',
        'remote_asn',
        'remote_ip',
        'local_ip',
        'status',
        'prefixes_received',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'remote_asn' => 'integer',
            'prefixes_received' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function telemetry(): HasMany
    {
        return $this->hasMany(BgpTelemetry::class, 'peer_id', 'peer_id');
    }
}
