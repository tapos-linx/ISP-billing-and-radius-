<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P2-12: SIP Interconnect Trunk Model
 */
class SipTrunk extends Model
{
    use HasFactory;

    protected $table = 'sip_trunks';

    protected $primaryKey = 'trunk_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'trunk_id',
        'tenant_id',
        'name',
        'host',
        'port',
        'auth_username',
        'auth_password',
        'max_channels',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'port' => 'integer',
            'max_channels' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function cdrs(): HasMany
    {
        return $this->hasMany(VoipCdr::class, 'trunk_id', 'trunk_id');
    }
}
