<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-4: Bank Statement Line Item Model
 */
class BankStatementLine extends Model
{
    use HasFactory;

    protected $table = 'bank_statement_lines';

    protected $primaryKey = 'line_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'line_id',
        'statement_id',
        'tenant_id',
        'transaction_date',
        'reference_number',
        'description',
        'amount_bdt',
        'matched_invoice_id',
        'match_status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'transaction_date' => 'date',
            'amount_bdt' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'statement_id', 'statement_id');
    }

    public function matchedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'matched_invoice_id', 'invoice_id');
    }
}
