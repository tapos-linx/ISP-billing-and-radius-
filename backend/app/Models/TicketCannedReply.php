<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * P0-13: Helpdesk Pre-Approved Canned Reply Model
 */
class TicketCannedReply extends Model
{
    protected $table = 'ticket_canned_replies';

    protected $primaryKey = 'reply_id';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'title',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
