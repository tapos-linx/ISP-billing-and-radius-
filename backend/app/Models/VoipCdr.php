<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-12: VoIP / SIP Call Detail Record (CDR) Model
 */
class VoipCdr extends Model
{
    use HasFactory;

    protected $table = 'voip_cdrs';

    protected $primaryKey = 'cdr_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'cdr_id',
        'tenant_id',
        'trunk_id',
        'call_id',
        'caller_e164',
        'callee_e164',
        'started_at',
        'answered_at',
        'ended_at',
        'duration_seconds',
        'billable_seconds',
        'rate_per_minute_bdt',
        'cost_bdt',
        'disposition',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'duration_seconds' => 'integer',
            'billable_seconds' => 'integer',
            'rate_per_minute_bdt' => 'float',
            'cost_bdt' => 'float',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function trunk(): BelongsTo
    {
        return $this->belongsTo(SipTrunk::class, 'trunk_id', 'trunk_id');
    }
}
