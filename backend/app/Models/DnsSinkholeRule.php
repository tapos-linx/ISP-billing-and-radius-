<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P2-3: DNS Sinkhole Regulatory Block Rule Model
 */
class DnsSinkholeRule extends Model
{
    use HasFactory;

    protected $table = 'dns_sinkhole_rules';

    protected $primaryKey = 'rule_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'tenant_id',
        'domain',
        'category',
        'sinkhole_ip',
        'is_active',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function hits(): HasMany
    {
        return $this->hasMany(DnsSinkholeHit::class, 'rule_id', 'rule_id');
    }
}
