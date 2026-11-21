<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-10: Carrier Grade NAT (CGNAT) Port Block Allocation & IPDR Binding Model
 */
class CgnatBinding extends Model
{
    use HasFactory;

    protected $table = 'cgnat_bindings';

    protected $primaryKey = 'binding_id';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'subscriber_id',
        'private_ip',
        'public_ip',
        'port_start',
        'port_end',
        'allocated_at',
        'released_at',
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'port_start' => 'integer',
            'port_end' => 'integer',
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
            'retained_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
    }
}
