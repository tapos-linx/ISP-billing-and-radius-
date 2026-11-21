<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * P2-4: OTDR Optical Pulse Trace & Distance Localization Model
 */
class OtdrTrace extends Model
{
    use HasFactory;

    protected $table = 'otdr_traces';

    protected $primaryKey = 'trace_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'trace_id',
        'tenant_id',
        'circuit_or_port_ref',
        'optical_wavelength_nm',
        'fiber_refractive_index',
        'pulse_width_ns',
        'fault_distance_meters',
        'optical_loss_db',
        'fault_type',
        'estimated_location_address',
        'traced_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'optical_wavelength_nm' => 'integer',
            'fiber_refractive_index' => 'float',
            'pulse_width_ns' => 'integer',
            'fault_distance_meters' => 'float',
            'optical_loss_db' => 'float',
            'traced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
