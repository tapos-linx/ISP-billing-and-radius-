<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Release 2.0 (Epic 1): Wi-Fi Hotspot Profile Model
 */
class HotspotProfile extends Model
{
    use HasFactory;

    protected $table = 'hotspot_profiles';

    protected $primaryKey = 'profile_id';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'name',
        'validity_seconds',
        'data_limit_bytes',
        'rate_limit_down_kbps',
        'rate_limit_up_kbps',
        'price_bdt',
        'simultaneous_use',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'validity_seconds' => 'integer',
            'data_limit_bytes' => 'integer',
            'rate_limit_down_kbps' => 'integer',
            'rate_limit_up_kbps' => 'integer',
            'price_bdt' => 'decimal:2',
            'simultaneous_use' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(HotspotVoucher::class, 'profile_id', 'profile_id');
    }
}
