<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Send test email" on Company settings: proves the mail settings deliver. */
class TestMail extends Mailable
{
    public function __construct(public string $mailerName, public string $sentAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').' test email');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.test');
    }
}
