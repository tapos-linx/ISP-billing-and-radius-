<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * P0-2: Helpdesk Ticket Activity & SLA Notification Email
 */
class TicketUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array{
     *   ticket_id: string|int,
     *   subject: string,
     *   status: string,
     *   last_comment?: string,
     *   actor_name?: string
     * } $ticketData
     */
    public function __construct(
        public array $ticketData
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Support Ticket #%s: %s [%s]', $this->ticketData['ticket_id'], $this->ticketData['subject'], strtoupper($this->ticketData['status']))
        );
    }

    public function content(): Content
    {
        $comment = $this->ticketData['last_comment'] ?? 'Your ticket status has been updated by our NOC engineering team.';
        $actor = $this->ticketData['actor_name'] ?? 'NOC Support Engineer';

        return new Content(
            htmlString: sprintf(
                '<p>Dear Customer,</p><p>There is an update on your support ticket <strong>#%s: %s</strong>.</p><p><strong>Status:</strong> %s<br><strong>Updated by:</strong> %s</p><blockquote style="border-left: 3px solid #0284c7; padding-left: 10px; color: #475569;">%s</blockquote><p>Tapos Linx NOC Operations Desk</p>',
                $this->ticketData['ticket_id'],
                $this->ticketData['subject'],
                strtoupper($this->ticketData['status']),
                $actor,
                nl2br(htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'))
            )
        );
    }
}
