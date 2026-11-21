<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-8: Field Ops Mobile Offline Event Model
 */
class MobileSyncEvent extends Model
{
    use HasFactory;

    protected $table = 'mobile_sync_events';

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'tenant_id',
        'technician_id',
        'device_uuid',
        'event_type',
        'event_data',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'event_data' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(FieldTechnician::class, 'technician_id', 'technician_id');
    }
}
