<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P1-15: Multi-Tier Reseller Prepaid Wallet Model
 */
class ResellerWallet extends Model
{
    use HasFactory;

    protected $table = 'reseller_wallets';

    protected $primaryKey = 'wallet_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'wallet_id',
        'tenant_id',
        'reseller_id',
        'balance_bdt',
        'credit_limit_bdt',
        'commission_rate_pct',
        'status',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'reseller_id' => 'integer',
            'balance_bdt' => 'float',
            'credit_limit_bdt' => 'float',
            'commission_rate_pct' => 'float',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ResellerTransaction::class, 'wallet_id', 'wallet_id');
    }
}
