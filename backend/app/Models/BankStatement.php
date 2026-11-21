<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P1-4: Bank Statement Model
 */
class BankStatement extends Model
{
    use HasFactory;

    protected $table = 'bank_statements';

    protected $primaryKey = 'statement_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'statement_id',
        'tenant_id',
        'bank_name',
        'account_number',
        'statement_date',
        'total_credits_bdt',
        'total_debits_bdt',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'statement_date' => 'date',
            'total_credits_bdt' => 'float',
            'total_debits_bdt' => 'float',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'statement_id', 'statement_id');
    }
}
