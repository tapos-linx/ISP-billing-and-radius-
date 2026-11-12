<?php
// File: app/Models/Invoice.php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $subscriber_id
 * @property string $invoice_number
 * @property string $amount
 * @property InvoiceStatus $status
 * @property string|null $payment_gateway
 * @property string|null $transaction_id
 * @property CarbonImmutable $billing_period_start
 * @property CarbonImmutable $billing_period_end
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Subscriber $subscriber
 */
class Invoice extends Model
{
    use HasFactory;

    protected $table = 'invoices';

    /** @var list<string> */
    protected $fillable = [
        'subscriber_id',
        'invoice_number',
        'amount',
        'status',
        'payment_gateway',
        'transaction_id',
        'billing_period_start',
        'billing_period_end',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subscriber_id'        => 'integer',
            'amount'               => 'decimal:2',
            'status'               => InvoiceStatus::class,
            'billing_period_start' => 'immutable_date',
            'billing_period_end'   => 'immutable_date',
            'paid_at'              => 'immutable_datetime',
            'created_at'           => 'immutable_datetime',
            'updated_at'           => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscriber, $this>
     */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id');
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::PAID;
    }
}
