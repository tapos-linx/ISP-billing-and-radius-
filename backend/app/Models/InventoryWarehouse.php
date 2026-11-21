<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P1-14: Inventory Warehouse Model
 */
class InventoryWarehouse extends Model
{
    use HasFactory;

    protected $table = 'inventory_warehouses';

    protected $primaryKey = 'warehouse_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'warehouse_id',
        'tenant_id',
        'name',
        'location',
        'manager_name',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'warehouse_id', 'warehouse_id');
    }
}
