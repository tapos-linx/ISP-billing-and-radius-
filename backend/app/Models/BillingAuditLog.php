<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingAuditLog extends Model
{
    protected $table = 'billing_audit_logs';
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'event_type',
        'entity_type',
        'entity_id',
        'actor_type',
        'actor_id',
        'prev_state',
        'new_state',
        'ip_address',
        'hash_signature',
        'prev_hash',
        'created_at',
    ];

    protected $casts = [
        'prev_state' => 'array',
        'new_state'  => 'array',
        'created_at' => 'datetime',
    ];
}
