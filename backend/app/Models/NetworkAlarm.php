<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P1-7 & P1-8: Network Alarm and Root Cause Suppression Model
 */
class NetworkAlarm extends Model
{
    use HasFactory;

    protected $table = 'network_alarms';

    protected $primaryKey = 'alarm_id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'device_id',
        'onu_id',
        'alarm_type',
        'severity',
        'is_root_cause',
        'suppressed_by_alarm_id',
        'status',
        'detected_at',
        'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'alarm_id' => 'integer',
            'tenant_id' => 'integer',
            'is_root_cause' => 'boolean',
            'suppressed_by_alarm_id' => 'integer',
            'detected_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'device_id', 'device_id');
    }

    public function onu(): BelongsTo
    {
        return $this->belongsTo(Onu::class, 'onu_id', 'onu_id');
    }

    public function parentAlarm(): BelongsTo
    {
        return $this->belongsTo(self::class, 'suppressed_by_alarm_id', 'alarm_id');
    }

    public function childAlarms(): HasMany
    {
        return $this->hasMany(self::class, 'suppressed_by_alarm_id', 'alarm_id');
    }
}
