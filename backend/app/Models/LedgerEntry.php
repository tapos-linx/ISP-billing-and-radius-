<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    protected $table = 'ledger_entries';
    protected $primaryKey = 'entry_id';
    public $timestamps = false;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'invoice_id',
        'payment_id',
        'subscriber_id',
        'tenant_id',
        'debit_bdt',
        'credit_bdt',
        'description',
        'posted_at',
    ];

    protected $casts = [
        'debit_bdt'  => 'float',
        'credit_bdt' => 'float',
        'posted_at'  => 'datetime',
        'created_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'invoice_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'payment_id');
    }
}
