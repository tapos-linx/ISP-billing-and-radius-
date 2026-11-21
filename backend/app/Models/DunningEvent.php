<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * P0-6: Dunning Event Model
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $subscriber_id
 * @property string $invoice_id
 * @property string $stage
 * @property float $fee_assessed_bdt
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
class DunningEvent extends Model
{
    use HasFactory;

    protected $table = 'dunning_events';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'subscriber_id',
        'invoice_id',
        'stage',
        'fee_assessed_bdt',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'fee_assessed_bdt' => 'decimal:2',
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
