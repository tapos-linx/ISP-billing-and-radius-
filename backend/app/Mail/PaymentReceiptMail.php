<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Pdf\PdfInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * P0-2: Payment Receipt Confirmation Email with Attached Paid Mushak-6.3 PDF
 */
class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public ?Invoice $invoice = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Payment Receipt: %s (BDT %.2f) - Tapos Linx Broadband', $this->payment->trx_id, (float) $this->payment->amount_bdt)
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<p>Dear Subscriber,</p><p>We have successfully received your payment of <strong>BDT %.2f</strong> via <strong>%s</strong> (Transaction ID: <code>%s</code>).</p><p>Your broadband internet line is active and synchronized with high-speed profile.</p><p>Thank you for choosing Tapos Linx Carrier Services.</p>',
                (float) $this->payment->amount_bdt,
                strtoupper((string) $this->payment->gateway),
                $this->payment->trx_id
            )
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->invoice) {
            return [];
        }

        $pdfService = app(PdfInvoiceService::class);

        return [
            Attachment::fromData(
                fn () => $pdfService->generatePdf($this->invoice),
                sprintf('mushak-6.3-receipt-%s.pdf', substr((string) $this->invoice->invoice_id, 0, 8))
            )->withMime('application/pdf'),
        ];
    }
}
