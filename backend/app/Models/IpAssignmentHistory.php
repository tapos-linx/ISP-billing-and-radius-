<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P0-12: Regulatory IP Allocation & Forensic Cyber-Abuse Audit Model
 */
class IpAssignmentHistory extends Model
{
    use HasFactory;

    protected $table = 'ip_assignment_history';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'subscriber_id',
        'framed_ip',
        'delegated_ipv6_prefix',
        'nas_ip',
        'mac_address',
        'assigned_at',
        'released_at',
        'session_id',
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'assigned_at' => 'datetime',
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
