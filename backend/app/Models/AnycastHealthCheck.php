<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-9: Anycast Node Health Check Record
 */
class AnycastHealthCheck extends Model
{
    use HasFactory;

    protected $table = 'anycast_health_checks';

    public $timestamps = false;

    protected $fillable = [
        'node_id',
        'auth_latency_ms',
        'acct_latency_ms',
        'replication_lag_seconds',
        'bgp_session_up',
        'packet_loss_pct',
        'is_healthy',
        'details',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'auth_latency_ms' => 'float',
            'acct_latency_ms' => 'float',
            'replication_lag_seconds' => 'float',
            'bgp_session_up' => 'boolean',
            'packet_loss_pct' => 'float',
            'is_healthy' => 'boolean',
            'details' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AnycastNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(AnycastNode::class, 'node_id', 'id');
    }
}
