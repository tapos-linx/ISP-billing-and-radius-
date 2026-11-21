<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P0-10: Optical Network Unit (ONU/ONT) Model
 */
class Onu extends Model
{
    use HasFactory;

    protected $table = 'onus';

    protected $primaryKey = 'onu_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'onu_id',
        'tenant_id',
        'olt_id',
        'subscriber_id',
        'pon_port',
        'onu_index',
        'serial_number',
        'vlan_id',
        'status',
        'rx_power_dbm',
        'tx_power_dbm',
        'distance_m',
        'last_seen_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'onu_index' => 'integer',
            'vlan_id' => 'integer',
            'rx_power_dbm' => 'float',
            'tx_power_dbm' => 'float',
            'distance_m' => 'integer',
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class, 'olt_id', 'olt_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
    }
}
