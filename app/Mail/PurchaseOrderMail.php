<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use App\Models\Setting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent only from SendPurchaseOrderEmail (a queued job), never in a web request.
 */
class PurchaseOrderMail extends Mailable
{
    public function __construct(
        public PurchaseOrder $po,
        public string $emailSubject,
        public ?string $messageText,
        private string $pdf,
        private string $pdfName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->emailSubject, replyTo: array_filter([Setting::get('company.email')]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.purchase-order', with: [
            'po' => $this->po,
            'messageText' => $this->messageText,
            'companyName' => Setting::get('company.name', config('app.name')),
        ]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->pdfName)->withMime('application/pdf')];
    }
}
