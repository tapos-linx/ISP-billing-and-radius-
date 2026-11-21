<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-5: Cashier Retail Payment Collection Model
 */
class CashCollection extends Model
{
    use HasFactory;

    protected $table = 'cash_collections';

    protected $primaryKey = 'collection_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'collection_id',
        'shift_id',
        'tenant_id',
        'subscriber_id',
        'invoice_id',
        'receipt_number',
        'amount_bdt',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'amount_bdt' => 'float',
            'collected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'shift_id', 'shift_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }
}
