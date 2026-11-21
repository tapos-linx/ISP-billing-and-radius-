<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * P0-5: Credit Note Model
 *
 * @property int $id
 * @property string $credit_note_number
 * @property int $tenant_id
 * @property string $invoice_id
 * @property string $subscriber_id
 * @property float $subtotal_bdt
 * @property float $vat_bdt
 * @property float $total_bdt
 * @property string $reason
 * @property string $status
 * @property Carbon|null $created_at
 */
class CreditNote extends Model
{
    use HasFactory;

    protected $table = 'credit_notes';

    public $timestamps = false;

    protected $fillable = [
        'credit_note_number',
        'tenant_id',
        'invoice_id',
        'subscriber_id',
        'subtotal_bdt',
        'vat_bdt',
        'total_bdt',
        'reason',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'subtotal_bdt' => 'decimal:2',
            'vat_bdt' => 'decimal:2',
            'total_bdt' => 'decimal:2',
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
}
