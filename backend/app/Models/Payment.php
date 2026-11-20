<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasUuids;

    protected $table = 'payments';
    protected $primaryKey = 'payment_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'subscriber_id',
        'tenant_id',
        'amount_bdt',
        'gateway',
        'trx_id',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'amount_bdt' => 'float',
        'paid_at'    => 'datetime',
        'created_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'payment_id', 'payment_id');
    }
}
