<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Release 2.0 (Epic 1): Wi-Fi Hotspot Sharded Voucher Model
 */
class HotspotVoucher extends Model
{
    use HasFactory;

    protected $table = 'hotspot_vouchers';

    protected $primaryKey = 'voucher_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $fillable = [
        'voucher_id',
        'tenant_id',
        'profile_id',
        'batch_id',
        'code',
        'pin',
        'status',
        'time_limit_seconds',
        'data_limit_bytes',
        'used_time_seconds',
        'used_bytes',
        'rate_limit_down_kbps',
        'rate_limit_up_kbps',
        'price_bdt',
        'mac_address',
        'first_login_at',
        'expires_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'profile_id' => 'integer',
            'time_limit_seconds' => 'integer',
            'data_limit_bytes' => 'integer',
            'used_time_seconds' => 'integer',
            'used_bytes' => 'integer',
            'rate_limit_down_kbps' => 'integer',
            'rate_limit_up_kbps' => 'integer',
            'price_bdt' => 'decimal:2',
            'first_login_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HotspotProfile::class, 'profile_id');
    }

    public function isUsable(): bool
    {
        if (! in_array($this->status, ['GENERATED', 'PURCHASED', 'ACTIVE'], true)) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->data_limit_bytes > 0 && $this->used_bytes >= $this->data_limit_bytes) {
            return false;
        }

        if ($this->time_limit_seconds > 0 && $this->used_time_seconds >= $this->time_limit_seconds) {
            return false;
        }

        return true;
    }

    /**
     * Get WISPr / FreeRADIUS VSA attributes for captive portal & MikroTik Hotspot
     */
    public function getWisprAttributes(): array
    {
        $downBps = $this->rate_limit_down_kbps * 1024;
        $upBps = $this->rate_limit_up_kbps * 1024;
        $rateLimitStr = sprintf('%dM/%dM', intval($this->rate_limit_down_kbps / 1024), intval($this->rate_limit_up_kbps / 1024));

        $remainingSeconds = $this->time_limit_seconds > 0
            ? max(0, $this->time_limit_seconds - $this->used_time_seconds)
            : 86400;

        return [
            'WISPr-Bandwidth-Max-Down' => $downBps,
            'WISPr-Bandwidth-Max-Up' => $upBps,
            'Mikrotik-Rate-Limit' => $rateLimitStr,
            'Session-Timeout' => $remainingSeconds,
            'Idle-Timeout' => 300,
        ];
    }
}
