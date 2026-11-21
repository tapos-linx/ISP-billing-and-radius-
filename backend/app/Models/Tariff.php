<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tariff Plan Model
 *
 * @property int $tariff_id
 * @property int $tenant_id
 * @property string $name
 * @property string $rate_limit
 * @property float $price_bdt
 * @property int $validity_days
 * @property Carbon|null $created_at
 */
class Tariff extends Model
{
    use HasFactory;

    protected $table = 'tariffs';

    protected $primaryKey = 'tariff_id';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'name',
        'rate_limit',
        'price_bdt',
        'validity_days',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'price_bdt' => 'decimal:2',
            'validity_days' => 'integer',
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
}
