<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-15: Reseller Wallet Transaction Ledger Model
 */
class ResellerTransaction extends Model
{
    use HasFactory;

    protected $table = 'reseller_transactions';

    protected $primaryKey = 'transaction_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'wallet_id',
        'tenant_id',
        'type',
        'amount_bdt',
        'balance_after_bdt',
        'reference_id',
        'description',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'amount_bdt' => 'float',
            'balance_after_bdt' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(ResellerWallet::class, 'wallet_id', 'wallet_id');
    }
}
