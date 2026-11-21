<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * P1-19: Outbound Webhook Subscription Model
 */
class WebhookSubscription extends Model
{
    use HasFactory;

    protected $table = 'webhook_subscriptions';

    protected $primaryKey = 'subscription_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'subscription_id',
        'tenant_id',
        'target_url',
        'secret',
        'events',
        'is_active',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'events' => 'array',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id', 'subscription_id');
    }
}
