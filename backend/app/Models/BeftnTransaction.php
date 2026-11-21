<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P2-6: BEFTN Direct Debit Transaction
 */
class BeftnTransaction extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'beftn_transactions';

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'invoice_id',
        'subscriber_id',
        'instruction_id',
        'mandate_reference',
        'customer_bank_name',
        'customer_routing_no',
        'customer_account_no',
        'account_title',
        'amount_bdt',
        'status',
        'return_code',
        'failure_reason',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'amount_bdt' => 'float',
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
     * @return BelongsTo<BeftnBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(BeftnBatch::class, 'batch_id', 'id');
    }

    /**
     * @return BelongsTo<Subscriber, $this>
     */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
    }
}
