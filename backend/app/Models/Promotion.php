<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * P1-16: Promotions and Discount Coupons Model
 */
class Promotion extends Model
{
    use HasFactory;

    protected $table = 'promotions';

    protected $primaryKey = 'promo_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'promo_id',
        'tenant_id',
        'code',
        'discount_type',
        'discount_value',
        'min_plan_amount_bdt',
        'valid_from',
        'valid_until',
        'usage_limit',
        'used_count',
        'active',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'discount_value' => 'float',
            'min_plan_amount_bdt' => 'float',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
