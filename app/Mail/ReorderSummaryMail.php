<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The 7am reorder summary for managers (reorder:daily-email). Nothing is ordered by it. */
class ReorderSummaryMail extends Mailable
{
    /** @param  list<array{supplier: string, to_order: int, value: float, min: ?float, meets_min: bool, stockouts: list<string>}>  $suppliers */
    public function __construct(public array $suppliers, public int $stockouts, public string $date) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Reorder suggestions {$this->date}: {$this->stockouts} ".($this->stockouts === 1 ? 'product runs' : 'products run').' out before the next delivery');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reorder-summary', with: ['url' => route('reorder.index')]);
    }
}
