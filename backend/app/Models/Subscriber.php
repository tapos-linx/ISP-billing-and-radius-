<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 6: Multi-Tenant Scoped Subscriber Model
 */
class Subscriber extends Model
{
    use HasFactory;

    protected $table = 'subscribers';

    protected $primaryKey = 'subscriber_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'subscriber_id',
        'tenant_id',
        'tariff_id',
        'username',
        'mac_address',
        'vlan_id',
        'bandwidth_profile',
        'status',
        'expires_at',
        'grace_until',
        'vacation_until',
        'auto_pay_enabled',
        'pro_rata_balance',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'tariff_id' => 'integer',
            'vlan_id' => 'integer',
            'auto_pay_enabled' => 'boolean',
            'pro_rata_balance' => 'float',
            'expires_at' => 'datetime',
            'grace_until' => 'datetime',
            'vacation_until' => 'datetime',
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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscriber_id', 'subscriber_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'subscriber_id', 'subscriber_id');
    }
}
