<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-14: Serialized Inventory Item Model
 */
class InventoryItem extends Model
{
    use HasFactory;

    protected $table = 'inventory_items';

    protected $primaryKey = 'item_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'item_id',
        'tenant_id',
        'warehouse_id',
        'subscriber_id',
        'serial_number',
        'model',
        'item_type',
        'status',
        'unit_cost_bdt',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'unit_cost_bdt' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id', 'warehouse_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
    }
}
