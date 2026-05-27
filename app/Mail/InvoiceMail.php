<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * 帳票（見積書/注文書/請求書）添付メール
 *  - subject / body は呼び出し側で指定（テナントテンプレ展開済）
 *  - attachments は [{ name, content (binary), mime }] 配列
 *  - From / Reply-To はログインユーザー(担当者)個人。Reply-To 個人化で返信が担当者へ届く。
 */
class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string $subjectText
     * @param string $bodyText
     * @param array<int, array{name:string, content:string, mime:string}> $attachmentItems
     * @param string $senderName  ログインユーザー名 (空なら config('mail.from.name'))
     * @param string $senderEmail ログインユーザーemail (空なら config('mail.from.address'))
     */
    public function __construct(
        public readonly string $subjectText,
        public readonly string $bodyText,
        public readonly array  $attachmentItems,
        public readonly string $senderName  = '',
        public readonly string $senderEmail = '',
    ) {}

    public function envelope(): Envelope
    {
        // From / Reply-To 共に担当者個人。Laravel は addReplyTo で global を残してしまうため、
        // Symfony Email の setReplyTo を using callback で上書きする (ReplyMail と同じパターン)。
        $fromEmail = $this->senderEmail ?: config('mail.from.address');
        $fromName  = $this->senderName  ?: config('mail.from.name');

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
            from: new Address($fromEmail, $fromName),
            replyTo: $replyTo,
            subject: $this->subjectText,
            using: $using,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mails.invoice',
            text: 'mails.invoice_text',
            with: [
                'body'    => $this->bodyText,
                'subject' => $this->subjectText,
            ],
        );
    }

    public function attachments(): array
    {
        $out = [];
        foreach ($this->attachmentItems as $item) {
            $out[] = Attachment::fromData(fn() => $item['content'], $item['name'])
                ->withMime($item['mime'] ?? 'application/pdf');
        }
        return $out;
    }
}
