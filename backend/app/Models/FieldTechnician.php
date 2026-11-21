<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P1-13: Field Workforce Technician Model
 */
class FieldTechnician extends Model
{
    use HasFactory;

    protected $table = 'field_technicians';

    protected $primaryKey = 'technician_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'technician_id',
        'tenant_id',
        'name',
        'phone',
        'email',
        'zone',
        'status',
        'current_lat',
        'current_lng',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'current_lat' => 'float',
            'current_lng' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'technician_id', 'technician_id');
    }
}
