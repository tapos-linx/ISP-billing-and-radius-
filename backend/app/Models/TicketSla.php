<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P0-13: Helpdesk Service Level Agreement (SLA) Policy Model
 */
class TicketSla extends Model
{
    protected $table = 'ticket_slas';

    protected $primaryKey = 'sla_id';

    public $timestamps = false;

    protected $fillable = [
        'priority',
        'response_time_minutes',
        'resolution_time_minutes',
    ];

    protected function casts(): array
    {
        return [
            'response_time_minutes' => 'integer',
            'resolution_time_minutes' => 'integer',
        ];
    }
}
