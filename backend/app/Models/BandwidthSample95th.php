<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-7: 95th Percentile Periodic Bandwidth Sample Model
 */
class BandwidthSample95th extends Model
{
    use HasFactory;

    protected $table = 'bandwidth_samples_95th';

    protected $primaryKey = 'sample_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'circuit_id',
        'tenant_id',
        'sampled_at',
        'in_mbps',
        'out_mbps',
        'peak_mbps',
    ];

    protected function casts(): array
    {
        return [
            'sample_id' => 'integer',
            'tenant_id' => 'integer',
            'in_mbps' => 'float',
            'out_mbps' => 'float',
            'peak_mbps' => 'float',
            'sampled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(EnterpriseCircuit::class, 'circuit_id', 'circuit_id');
    }
}
