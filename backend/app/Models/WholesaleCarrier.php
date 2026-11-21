<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P2-5: Wholesale Carrier Interconnect Partner Model
 */
class WholesaleCarrier extends Model
{
    use HasFactory;

    protected $table = 'wholesale_carriers';

    protected $primaryKey = 'carrier_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'carrier_id',
        'tenant_id',
        'carrier_name',
        'carrier_code',
        'account_number',
        'rate_per_mbps_bdt',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'rate_per_mbps_bdt' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tunnels(): HasMany
    {
        return $this->hasMany(L2tpTunnel::class, 'carrier_id', 'carrier_id');
    }
}
