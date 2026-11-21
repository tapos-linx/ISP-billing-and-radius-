<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-3: DNS Sinkhole Interception Hit Log Model
 */
class DnsSinkholeHit extends Model
{
    use HasFactory;

    protected $table = 'dns_sinkhole_hits';

    protected $primaryKey = 'hit_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'tenant_id',
        'client_ip',
        'queried_domain',
        'blocked_at',
    ];

    protected function casts(): array
    {
        return [
            'hit_id' => 'integer',
            'tenant_id' => 'integer',
            'blocked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DnsSinkholeRule::class, 'rule_id', 'rule_id');
    }
}
