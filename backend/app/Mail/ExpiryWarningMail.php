<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Subscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * P0-2: Pre-Expiry Alert Email for Subscriber
 */
class ExpiryWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Subscriber $subscriber,
        public int $daysRemaining
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Urgent: Broadband Subscription Expires in %d Day(s) - %s', $this->daysRemaining, $this->subscriber->username)
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<p>Dear %s,</p><p>This is a friendly reminder that your broadband Internet subscription will expire in <strong>%d day(s)</strong> (on <strong>%s</strong>).</p><p>To prevent automatic disconnection and redirection to the walled garden, please renew your plan using bKash, Nagad, or Debit/Credit Card.</p><p>Best regards,<br>Tapos Linx Customer Operations</p>',
                $this->subscriber->username,
                $this->daysRemaining,
                $this->subscriber->expires_at ? $this->subscriber->expires_at->format('d M Y H:i') : 'Shortly'
            )
        );
    }
}
