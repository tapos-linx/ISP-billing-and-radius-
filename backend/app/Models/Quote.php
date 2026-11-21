<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-11: Sales Quote Model
 */
class Quote extends Model
{
    use HasFactory;

    protected $table = 'quotes';

    protected $primaryKey = 'quote_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'quote_id',
        'tenant_id',
        'lead_id',
        'tariff_id',
        'installation_charge_bdt',
        'monthly_recurring_bdt',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'tariff_id' => 'integer',
            'installation_charge_bdt' => 'float',
            'monthly_recurring_bdt' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id', 'lead_id');
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class, 'tariff_id', 'tariff_id');
    }
}
