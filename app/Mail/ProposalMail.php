<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProposalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $body,
        public readonly string $senderName = '',
        public readonly string $senderEmail = '',
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = $this->senderEmail
            ? [new Address($this->senderEmail, $this->senderName)]
            : [];

        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                $this->senderName ?: config('mail.from.name'),
            ),
            replyTo: $replyTo,
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.proposal');
    }
}
