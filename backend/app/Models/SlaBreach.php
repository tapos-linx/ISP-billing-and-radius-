<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-1: Enterprise SLA Breach & Penalty Credit Model
 */
class SlaBreach extends Model
{
    use HasFactory;

    protected $table = 'sla_breaches';

    protected $primaryKey = 'breach_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'breach_id',
        'circuit_id',
        'tenant_id',
        'billing_month',
        'actual_availability_pct',
        'downtime_minutes',
        'penalty_credit_bdt',
        'credit_note_id',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'credit_note_id' => 'integer',
            'actual_availability_pct' => 'float',
            'downtime_minutes' => 'integer',
            'penalty_credit_bdt' => 'float',
            'created_at' => 'datetime',
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

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class, 'credit_note_id', 'credit_note_id');
    }
}
