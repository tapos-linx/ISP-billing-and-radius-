<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Pdf\PdfInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * P0-2: Transactional Invoice Generated Email with Attached Mushak-6.3 PDF
 */
class InvoiceGeneratedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Invoice #%s Issued - Tapos Linx Broadband', substr((string) $this->invoice->invoice_id, 0, 8))
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<p>Dear Subscriber,</p><p>Your invoice <strong>#%s</strong> for <strong>BDT %.2f</strong> has been generated and is now due on <strong>%s</strong>.</p><p>Please find attached your official Bangladesh NBR Mushak-6.3 VAT Tax Invoice.</p><p>Pay instantly via bKash, Nagad, or Credit Card to avoid service disruption.</p><p>Regards,<br>Tapos Linx Carrier NOC</p>',
                $this->invoice->invoice_id,
                $this->invoice->total_bdt ?: $this->invoice->amount_bdt,
                $this->invoice->due_date ? $this->invoice->due_date->format('d M Y') : 'Due in 7 days'
            )
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdfService = app(PdfInvoiceService::class);

        return [
            Attachment::fromData(
                fn () => $pdfService->generatePdf($this->invoice),
                sprintf('mushak-6.3-invoice-%s.pdf', substr((string) $this->invoice->invoice_id, 0, 8))
            )->withMime('application/pdf'),
        ];
    }
}
