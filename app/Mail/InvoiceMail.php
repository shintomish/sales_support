<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 請求書 添付メール
 *  - subject / body は呼び出し側で指定（テナントテンプレ展開済）
 *  - attachments は [{ name, content (binary), mime }] 配列
 */
class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param string                                      $subjectText
     * @param string                                      $bodyText
     * @param array<int, array{name:string, content:string, mime:string}> $attachments
     */
    public function __construct(
        public readonly string $subjectText,
        public readonly string $bodyText,
        public readonly array  $attachmentItems,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText);
    }

    public function content(): Content
    {
        return new Content(
            text: 'mails.invoice_text',
            with: ['body' => $this->bodyText],
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
