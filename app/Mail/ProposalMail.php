<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class ProposalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $body,
        public readonly string $senderName = '',
        public readonly string $senderEmail = '',
        public readonly array $uploadedFiles = [],
        public readonly string $messageId = '',
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

    public function attachments(): array
    {
        return collect($this->uploadedFiles)
            ->filter(fn($f) => $f instanceof UploadedFile)
            ->map(fn(UploadedFile $f) => Attachment::fromPath($f->getRealPath())
                ->as($f->getClientOriginalName())
                ->withMime($f->getMimeType() ?? 'application/octet-stream'))
            ->all();
    }
}
