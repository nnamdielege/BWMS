<?php

namespace App\Jobs;

use App\Mail\PurchaseOrderMail;
use App\Models\PurchaseOrderEmail;
use App\Services\Purchasing\PurchaseOrderPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * NFR-6: PDF rendering and SMTP happen here, in a queue worker, never in a web
 * request. Every attempt is reflected on the purchase_order_emails row.
 */
class SendPurchaseOrderEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60; // queue retry_after (90s) must exceed this

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public PurchaseOrderEmail $email) {}

    public function handle(PurchaseOrderPdf $pdf): void
    {
        $email = $this->email->fresh(['purchaseOrder']);
        if ($email->status === 'sent') {
            return; // retried after a success: never send twice
        }
        $email->increment('attempts');

        $po = $email->purchaseOrder;
        $message = Mail::to($email->to);
        if ($email->cc) {
            $message->cc(array_map('trim', explode(',', $email->cc)));
        }
        $message->send(new PurchaseOrderMail($po, $email->subject, $email->message, $pdf->render($po), $pdf->filename($po)));

        $email->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        $this->email->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
    }
}
