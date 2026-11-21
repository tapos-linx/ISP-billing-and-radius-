<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-9: NetFlow v5/v9 Flow Accounting & Protocol Breakdown Model
 */
class NetflowRecord extends Model
{
    use HasFactory;

    protected $table = 'netflow_records';

    protected $primaryKey = 'flow_id';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'subscriber_id',
        'src_ip',
        'dst_ip',
        'protocol',
        'src_port',
        'dst_port',
        'bytes',
        'packets',
        'category',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'src_port' => 'integer',
            'dst_port' => 'integer',
            'bytes' => 'integer',
            'packets' => 'integer',
            'captured_at' => 'datetime',
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
