<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PurchaseOrderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        protected string $recipient,
        protected string $emailSubject,
        protected string $emailMessage,
        protected string $orderNumber,
        protected int $orderId,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
            to: $this->recipient,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-order',
            with: [
                'emailMessage' => $this->emailMessage,  // ← Changed from 'message'
                'orderNumber' => $this->orderNumber,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        try {
            // Generate PDF fresh when email is sent
            $order = PurchaseOrder::with('items.product', 'supplier', 'warehouse')
                ->findOrFail($this->orderId);

            $html = view('purchase-orders.pdf', ['order' => $order])->render();
            $mpdf = new \Mpdf\Mpdf();
            $mpdf->AddPage();
            $mpdf->WriteHTML($html);
            $pdfContent = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

            return [
                Attachment::fromData(
                    fn() => $pdfContent,
                    "PO-{$this->orderNumber}.pdf"
                )->withMime('application/pdf'),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to generate PDF for email', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return empty attachments if PDF generation fails
            return [];
        }
    }
}