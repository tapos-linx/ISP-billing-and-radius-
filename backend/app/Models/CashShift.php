<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P1-5: Cashier Cash Drawer Shift Model
 */
class CashShift extends Model
{
    use HasFactory;

    protected $table = 'cash_shifts';

    protected $primaryKey = 'shift_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'shift_id',
        'tenant_id',
        'cashier_user_id',
        'opened_at',
        'closed_at',
        'starting_cash_bdt',
        'collected_cash_bdt',
        'closing_cash_bdt',
        'discrepancy_bdt',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'cashier_user_id' => 'integer',
            'starting_cash_bdt' => 'float',
            'collected_cash_bdt' => 'float',
            'closing_cash_bdt' => 'float',
            'discrepancy_bdt' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CashCollection::class, 'shift_id', 'shift_id');
    }
}
