<?php

namespace App\Mail;

use App\Models\PurchaseOrder;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PurchaseOrderMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        protected string $recipient,
        protected string $emailSubject,
        protected string $emailMessage,
        protected string $orderNumber,
        protected int $orderId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
            to: $this->recipient,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-order',
            with: [
                'emailMessage' => $this->emailMessage,
                'orderNumber' => $this->orderNumber,
            ],
        );
    }

    public function attachments(): array
    {
        try {
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

            return [];
        }
    }
}