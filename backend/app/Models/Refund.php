<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * P0-5: Refund Model
 *
 * @property int $id
 * @property string $refund_number
 * @property int $tenant_id
 * @property string|null $payment_id
 * @property string $invoice_id
 * @property string $subscriber_id
 * @property float $amount_bdt
 * @property string $method
 * @property string|null $gateway_refund_id
 * @property string|null $reason
 * @property string $status
 * @property Carbon|null $created_at
 */
class Refund extends Model
{
    use HasFactory;

    protected $table = 'refunds';

    public $timestamps = false;

    protected $fillable = [
        'refund_number',
        'tenant_id',
        'payment_id',
        'invoice_id',
        'subscriber_id',
        'amount_bdt',
        'method',
        'gateway_refund_id',
        'reason',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'amount_bdt' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
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
