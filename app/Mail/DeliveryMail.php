<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class DeliveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $body,
        public readonly string $senderName  = '',
        public readonly string $senderEmail = '',
        public readonly string $messageId   = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                $this->senderEmail ?: config('mail.from.address'),
                $this->senderName  ?: config('mail.from.name'),
            ),
            subject: $this->mailSubject,
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId ?: null,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.proposal');
    }
}
