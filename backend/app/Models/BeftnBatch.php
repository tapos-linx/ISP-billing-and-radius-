<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P2-6: BEFTN / NPSB / RTGS Direct Debit Clearing Batch
 */
class BeftnBatch extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'beftn_batches';

    protected $fillable = [
        'tenant_id',
        'batch_number',
        'settlement_date',
        'clearing_type',
        'total_count',
        'total_amount_bdt',
        'status',
        'originator_bank_code',
        'originator_routing_no',
        'originator_account_no',
        'originator_name',
        'file_reference',
        'batch_payload',
        'submitted_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'settlement_date' => 'date',
            'total_count' => 'integer',
            'total_amount_bdt' => 'float',
            'submitted_at' => 'datetime',
            'settled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * @return HasMany<BeftnTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(BeftnTransaction::class, 'batch_id', 'id');
    }
}
