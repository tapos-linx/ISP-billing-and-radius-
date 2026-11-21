<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * P1-11: Lead Model for Sales Pipeline
 */
class Lead extends Model
{
    use HasFactory;

    protected $table = 'leads';

    protected $primaryKey = 'lead_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'lead_id',
        'tenant_id',
        'name',
        'phone',
        'email',
        'address',
        'lat',
        'lng',
        'status',
        'stage',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function feasibilityCheck(): HasOne
    {
        return $this->hasOne(FeasibilityCheck::class, 'lead_id', 'lead_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'lead_id', 'lead_id');
    }
}
