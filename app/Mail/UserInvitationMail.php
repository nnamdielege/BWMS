<?php

namespace App\Mail;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public UserInvitation $invitation, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.user-invitation', with: [
            'name' => $this->invitation->name,
            'url' => route('invitations.show', $this->token),
            'expiresAt' => $this->invitation->expires_at->timezone(config('bwms.business_timezone'))->format('j M Y g:ia'),
        ]);
    }
}
