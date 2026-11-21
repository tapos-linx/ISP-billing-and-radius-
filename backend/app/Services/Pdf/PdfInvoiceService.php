<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use App\Models\Invoice;
use App\Models\Subscriber;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

/**
 * P0-2: Bangladesh NBR Mushak-6.3 Compliant VAT Tax Invoice PDF Generator
 * [Government of the People's Republic of Bangladesh, National Board of Revenue, Rule 40 (1) (c)]
 */
class PdfInvoiceService
{
    public const COMPANY_NAME = 'Tapos Linx Carrier ISP Ltd.';

    public const COMPANY_BIN = '001234567-0101';

    public const COMPANY_ADDRESS = 'Road 12, Banani Commercial Area, Dhaka-1213, Bangladesh';

    public const VAT_RATE_PERCENT = 5.00;

    /**
     * Generate raw binary PDF bytes for a given invoice.
     */
    public function generatePdf(Invoice $invoice): string
    {
        $html = $this->renderMushak63Html($invoice);

        /** @var \Barryvdh\DomPDF\PDF $pdf */
        $pdf = Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');

        return (string) $pdf->output();
    }

    /**
     * Render Mushak-6.3 HTML layout.
     */
    public function renderMushak63Html(Invoice $invoice): string
    {
        $subscriber = Subscriber::withoutGlobalScopes()
            ->where('subscriber_id', $invoice->subscriber_id)
            ->first();

        $subName = $subscriber ? $subscriber->username : 'Valued Subscriber';
        $subId = $invoice->subscriber_id;
        $mushakNo = $invoice->mushak_6_3_number ?: ('MUSHAK-6.3-'.strtoupper(substr(md5((string) $invoice->invoice_id), 0, 8)));
        $issueDate = $invoice->created_at ? Carbon::parse($invoice->created_at)->format('d/m/Y H:i:s') : Carbon::now()->format('d/m/Y H:i:s');
        $dueDate = $invoice->due_date ? Carbon::parse($invoice->due_date)->format('d/m/Y') : Carbon::now()->addDays(7)->format('d/m/Y');

        $totalBdt = (float) ($invoice->total_bdt ?: $invoice->amount_bdt);
        $vatBdt = (float) ($invoice->vat_amount_bdt ?: round($totalBdt - ($totalBdt / (1 + (self::VAT_RATE_PERCENT / 100))), 2));
        $subtotalBdt = (float) ($invoice->subtotal_bdt ?: ($totalBdt - $vatBdt));

        $qrData = sprintf('BIN:%s|MUSHAK:%s|INV:%s|TOTAL:%.2f|VAT:%.2f',
            self::COMPANY_BIN, $mushakNo, $invoice->invoice_id, $totalBdt, $vatBdt
        );

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Mushak 6.3 - Tax Invoice {$mushakNo}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 12px; color: #333; line-height: 1.4; padding: 20px; }
        .header { text-align: center; border-bottom: 2px solid #0284c7; padding-bottom: 10px; margin-bottom: 15px; }
        .header h2 { margin: 0; font-size: 16px; text-transform: uppercase; color: #0f172a; }
        .header h3 { margin: 3px 0; font-size: 13px; color: #475569; }
        .header .mushak-badge { display: inline-block; background-color: #0284c7; color: white; padding: 3px 10px; font-weight: bold; border-radius: 3px; font-size: 11px; margin-top: 5px; }
        .meta-table { width: 100%; margin-bottom: 15px; border-collapse: collapse; }
        .meta-table td { padding: 4px; vertical-align: top; }
        .items-table { width: 100%; border-collapse: collapse; margin-top: 15px; margin-bottom: 15px; }
        .items-table th { background-color: #f1f5f9; border: 1px solid #cbd5e1; padding: 8px; text-align: left; font-size: 11px; }
        .items-table td { border: 1px solid #cbd5e1; padding: 8px; font-size: 11px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-row { font-weight: bold; background-color: #f8fafc; }
        .footer { margin-top: 30px; border-top: 1px dashed #cbd5e1; padding-top: 10px; font-size: 10px; color: #64748b; }
        .signature-box { margin-top: 40px; width: 100%; }
        .signature-box td { text-align: center; padding-top: 40px; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Government of the People's Republic of Bangladesh</h2>
        <h3>National Board of Revenue (NBR)</h3>
        <div class="mushak-badge">TAX INVOICE (MUSHAK-6.3) [See Rule 40 (1) (c)]</div>
    </div>

    <table class="meta-table">
        <tr>
            <td width="55%">
                <strong>Registered Person's Name:</strong> {$this->esc(self::COMPANY_NAME)}<br/>
                <strong>BIN:</strong> {$this->esc(self::COMPANY_BIN)}<br/>
                <strong>Address:</strong> {$this->esc(self::COMPANY_ADDRESS)}
            </td>
            <td width="45%" class="text-right">
                <strong>Challan / Mushak-6.3 No:</strong> {$this->esc($mushakNo)}<br/>
                <strong>Issue Date & Time:</strong> {$this->esc($issueDate)}<br/>
                <strong>Payment Due Date:</strong> {$this->esc($dueDate)}
            </td>
        </tr>
        <tr>
            <td colspan="2" style="padding-top: 10px;">
                <strong>Buyer / Subscriber Details:</strong><br/>
                Username / Name: {$this->esc($subName)}<br/>
                Subscriber ID: {$this->esc((string) $subId)}<br/>
                Supply Destination: Bangladesh (Domestic ISP FTTH Service)
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th width="5%" class="text-center">Sl</th>
                <th width="45%">Description of Supply / Service</th>
                <th width="10%" class="text-center">Unit</th>
                <th width="10%" class="text-center">Qty</th>
                <th width="15%" class="text-right">Unit Price (BDT)</th>
                <th width="15%" class="text-right">Total Price (BDT)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="text-center">1</td>
                <td>Fixed Broadband Internet Service (Fiber-to-the-Home FTTH) - Monthly Subscription</td>
                <td class="text-center">Month</td>
                <td class="text-center">1</td>
                <td class="text-right">{$this->num($subtotalBdt)}</td>
                <td class="text-right">{$this->num($subtotalBdt)}</td>
            </tr>
            <tr class="total-row">
                <td colspan="5" class="text-right">Subtotal (Excl. VAT):</td>
                <td class="text-right">{$this->num($subtotalBdt)}</td>
            </tr>
            <tr class="total-row">
                <td colspan="5" class="text-right">NBR Output VAT (5%):</td>
                <td class="text-right">{$this->num($vatBdt)}</td>
            </tr>
            <tr class="total-row" style="background-color: #e2e8f0; font-size: 12px;">
                <td colspan="5" class="text-right">Grand Total Payable (Inclusive of VAT):</td>
                <td class="text-right">BDT {$this->num($totalBdt)}</td>
            </tr>
        </tbody>
    </table>

    <table class="signature-box">
        <tr>
            <td width="50%" style="border-top: 1px dotted #94a3b8; margin-right: 20px;">
                Prepared & Verified by System<br/>
                <strong>Verification Signature:</strong> {$this->esc(substr(hash('sha256', $qrData), 0, 16))}
            </td>
            <td width="50%" style="border-top: 1px dotted #94a3b8; margin-left: 20px;">
                For and on behalf of<br/>
                <strong>{$this->esc(self::COMPANY_NAME)}</strong>
            </td>
        </tr>
    </table>

    <div class="footer">
        * This is an electronically generated NBR Mushak-6.3 Tax Invoice under the Value Added Tax and Supplementary Duty Act, 2012. No manual signature required.
    </div>
</body>
</html>
HTML;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function num(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }
}
