<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email as SymfonyEmail;

class DeliveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $body,
        public readonly string $senderName      = '',
        public readonly string $senderEmail     = '',
        public readonly string $messageId       = '',
        public readonly array  $attachmentPaths = [],
        public readonly ?string $fromDisplayName = null,
    ) {}

    public function envelope(): Envelope
    {
        // 返信先(Reply-To)はログインユーザー(営業担当)個人にする。
        // 客先が返信ボタンを押した時に outsource@ ではなく担当者の個別アドレスに届くため、
        // /emails 自社タブで campaign 紐付けバッジが表示される (2026-05-27 fix)。
        // Laravel の Mailable は Envelope replyTo を addReplyTo（追加）するため、
        // config('mail.reply_to') が残ってしまう。Symfony 側で setReplyTo して上書きする。
        $replyTo = $this->senderEmail
            ? [new Address($this->senderEmail, $this->senderName)]
            : [];

        $using = [];
        if (!empty($replyTo)) {
            $using[] = function (SymfonyEmail $email) {
                $email->replyTo(new SymfonyAddress($this->senderEmail, $this->senderName));
            };
        }

        return new Envelope(
            from: new Address(
                config('mail.from.address'),
                $this->fromDisplayName ?: config('mail.from.name'),
            ),
            replyTo: $replyTo,
            subject: $this->mailSubject,
            using: $using,
        );
    }

    public function headers(): Headers
    {
        // Headers::messageId はブラケットなしで渡す（Symfony Mailerが自動付与）
        $mid = $this->messageId ? trim($this->messageId, '<>') : null;
        return new Headers(messageId: $mid);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.proposal');
    }

    public function attachments(): array
    {
        return array_map(
            fn($path) => Attachment::fromPath($path),
            $this->attachmentPaths
        );
    }
}
