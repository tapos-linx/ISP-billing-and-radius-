<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Mail\TicketUpdatedMail;
use App\Models\Subscriber;
use App\Models\Ticket;
use App\Models\TicketCannedReply;
use App\Models\TicketComment;
use App\Models\TicketSla;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * P0-13: Carrier Helpdesk Ticketing & SLA Escalation Service
 */
class TicketService
{
    /**
     * Create a new support ticket with SLA deadline calculation
     */
    public function createTicket(
        int $tenantId,
        string $subject,
        string $category,
        string $priority = 'medium',
        ?string $subscriberId = null,
        ?string $initialComment = null,
        ?int $assignedUserId = null
    ): Ticket {
        $now = Carbon::now();

        // Calculate SLA resolution target
        $sla = TicketSla::where('priority', strtolower($priority))->first();
        $minutes = $sla ? $sla->resolution_time_minutes : 480; // default 8 hours
        $slaDueAt = $now->copy()->addMinutes($minutes);

        $ticketNumber = 'TKT-'.$now->format('Ymd').'-'.strtoupper(Str::random(5));

        return DB::transaction(function () use (
            $tenantId, $ticketNumber, $subscriberId, $subject, $category,
            $priority, $assignedUserId, $slaDueAt, $initialComment, $now
        ) {
            $ticket = Ticket::withoutGlobalScopes()->create([
                'ticket_id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'ticket_number' => $ticketNumber,
                'subscriber_id' => $subscriberId,
                'subject' => $subject,
                'category' => $category,
                'priority' => $priority,
                'status' => 'open',
                'assigned_to_user_id' => $assignedUserId,
                'sla_due_at' => $slaDueAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($initialComment !== null && $initialComment !== '') {
                TicketComment::withoutGlobalScopes()->create([
                    'comment_id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'ticket_id' => (string) $ticket->ticket_id,
                    'is_internal' => false,
                    'comment' => $initialComment,
                    'created_at' => $now,
                ]);
            }

            Log::info(sprintf('Created ticket %s [%s] priority=%s SLA=%s',
                $ticketNumber, $subject, $priority, $slaDueAt->toDateTimeString()
            ));

            return $ticket;
        });
    }

    /**
     * Post a comment or resolution note and notify the customer
     */
    public function addComment(
        Ticket $ticket,
        string $comment,
        bool $isInternal = false,
        ?int $userId = null
    ): TicketComment {
        $now = Carbon::now();

        $ticketComment = TicketComment::withoutGlobalScopes()->create([
            'comment_id' => (string) Str::uuid(),
            'tenant_id' => (int) $ticket->tenant_id,
            'ticket_id' => (string) $ticket->ticket_id,
            'user_id' => $userId,
            'is_internal' => $isInternal,
            'comment' => $comment,
            'created_at' => $now,
        ]);

        $ticket->updated_at = $now;
        $ticket->save();

        // Notify subscriber via email if customer-facing
        if (! $isInternal && $ticket->subscriber_id) {
            $subscriber = Subscriber::withoutGlobalScopes()->where('subscriber_id', $ticket->subscriber_id)->first();
            if ($subscriber) {
                try {
                    Mail::to($subscriber->username.'@taposlinx.net')
                        ->send(new TicketUpdatedMail([
                            'ticket_id' => $ticket->ticket_number,
                            'subject' => $ticket->subject,
                            'status' => $ticket->status,
                            'last_comment' => $comment,
                            'actor_name' => $userId ? "NOC Engineer #{$userId}" : 'System Bot',
                        ]));
                } catch (\Throwable $e) {
                    Log::warning('Failed sending ticket update email: '.$e->getMessage());
                }
            }
        }

        return $ticketComment;
    }

    /**
     * Transition ticket to resolved
     */
    public function resolveTicket(Ticket $ticket, string $resolutionNotes): Ticket
    {
        $now = Carbon::now();

        $ticket->status = 'resolved';
        $ticket->resolved_at = $now;
        $ticket->save();

        $this->addComment($ticket, "Resolved: {$resolutionNotes}", false);

        return $ticket;
    }

    /**
     * Get canned replies
     */
    public function getCannedReplies(int $tenantId): array
    {
        return TicketCannedReply::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->toArray();
    }
}
