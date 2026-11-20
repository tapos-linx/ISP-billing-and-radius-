<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasUuids;

    protected $table = 'invoices';

    protected $primaryKey = 'invoice_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'subscriber_id',
        'tenant_id',
        'amount_bdt',
        'subtotal_bdt',
        'vat_rate_percent',
        'vat_amount_bdt',
        'total_bdt',
        'status',
        'due_date',
        'paid_at',
        'isp_bin_number',
        'mushak_6_3_number',
        'customer_bin_nid',
    ];

    protected $casts = [
        'amount_bdt' => 'float',
        'subtotal_bdt' => 'float',
        'vat_rate_percent' => 'float',
        'vat_amount_bdt' => 'float',
        'total_bdt' => 'float',
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id', 'invoice_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'invoice_id', 'invoice_id');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
