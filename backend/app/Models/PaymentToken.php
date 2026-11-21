<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P0-8: Tokenized Saved Payment Method Model
 */
class PaymentToken extends Model
{
    use HasFactory;

    protected $table = 'payment_tokens';

    protected $primaryKey = 'token_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'token_id',
        'tenant_id',
        'subscriber_id',
        'gateway',
        'customer_token',
        'payment_method_token',
        'card_brand',
        'card_last4',
        'exp_month',
        'exp_year',
        'is_default',
        'status',
        'retry_count',
        'last_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
            'retry_count' => 'integer',
            'last_retry_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
    }
}
