<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    protected $table = 'ledger_accounts';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'type',
        'normal_balance',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'account_id');
    }
}
