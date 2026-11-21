<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P0-10: Optical Line Terminal (OLT) Model
 */
class Olt extends Model
{
    use HasFactory;

    protected $table = 'olts';

    protected $primaryKey = 'olt_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'olt_id',
        'tenant_id',
        'name',
        'vendor',
        'ip_address',
        'snmp_community',
        'management_port',
        'auth_user',
        'auth_secret',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'management_port' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function onus(): HasMany
    {
        return $this->hasMany(Onu::class, 'olt_id', 'olt_id');
    }
}
