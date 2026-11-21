<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P1-13: Field Workforce Work Order Model
 */
class WorkOrder extends Model
{
    use HasFactory;

    protected $table = 'work_orders';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'tenant_id',
        'order_number',
        'subscriber_id',
        'ticket_id',
        'technician_id',
        'type',
        'priority',
        'status',
        'address',
        'lat',
        'lng',
        'scheduled_at',
        'completed_at',
        'notes',
        'customer_signature',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(FieldTechnician::class, 'technician_id', 'technician_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id', 'subscriber_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'ticket_id');
    }
}
