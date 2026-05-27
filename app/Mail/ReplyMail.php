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
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * 自社メール（@aizen-sol.co.jp 宛）への返信 Mailable。
 *
 * SelfMailsView の「返信」ボタンから呼び出される。
 * ProposalMail とほぼ同じ構造だが、以下の点で異なる:
 *   - In-Reply-To / References ヘッダで受信スレッドを維持
 *   - body は plain text 想定 (emails.proposal blade をそのまま使う)
 *
 * In-Reply-To は受信時に emails.rfc_message_id に保存された RFC822 Message-ID を使う。
 * 過去メール (2026-05-27 migration 以前) は rfc_message_id=null のためヘッダはスキップする。
 */
class ReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int, string> $uploadedFiles 一時保存された添付ファイルの絶対パス
     * @param string $messageId 新規送信メールの Message-ID (< > 込み)
     * @param string|null $inReplyTo 元メールの RFC822 Message-ID (< > なし)。null の場合ヘッダ省略
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $body,
        public readonly string $senderName = '',
        public readonly string $senderEmail = '',
        public readonly array $uploadedFiles = [],
        public readonly string $messageId = '',
        public readonly ?string $inReplyTo = null,
        public readonly ?string $fromDisplayName = null,
    ) {}

    public function envelope(): Envelope
    {
        // From も Reply-To も「ログイン中の送信者」を使う（営業担当本人の宛先で届くようにする）。
        // From name は senderName（ログインユーザー名）を優先。fromDisplayName は使わない。
        // senderEmail が空の場合は config('mail.from') へフォールバック。
        $fromEmail = $this->senderEmail ?: config('mail.from.address');
        $fromName  = $this->senderName  ?: config('mail.from.name');

        $replyTo = $this->senderEmail
            ? [new Address($this->senderEmail, $this->senderName)]
            : [];

        // Laravel の Mailable は Envelope replyTo を addReplyTo（追加）するため、
        // config('mail.reply_to') が残ってしまう。Symfony 側で setReplyTo して上書きする。
        $using = [];
        if (!empty($replyTo)) {
            $using[] = function (SymfonyEmail $email) {
                $email->replyTo(new SymfonyAddress($this->senderEmail, $this->senderName));
            };
        }

        return new Envelope(
            from: new Address($fromEmail, $fromName),
            replyTo: $replyTo,
            subject: $this->mailSubject,
            using: $using,
        );
    }

    public function headers(): Headers
    {
        // Headers::messageId はブラケットなしで渡す（Symfony Mailer が自動付与）
        $mid = $this->messageId ? trim($this->messageId, '<>') : null;
        // In-Reply-To と References を text パラメータで生ヘッダ注入。
        // Laravel\Mail\Mailables\Headers::text は ['Header-Name' => 'value'] 形式を
        // そのまま Symfony Email に渡す。
        $text = [];
        if ($this->inReplyTo) {
            $clean = trim($this->inReplyTo, '<> ');
            $text['In-Reply-To'] = '<' . $clean . '>';
            // References は会話履歴を辿るため複数 ID 並べるのが原則だが、
            // 受信時に親 References を保存していないため In-Reply-To 同一の単一値で運用。
            // メールクライアントは In-Reply-To を優先するためスレッド維持には十分。
            $text['References'] = '<' . $clean . '>';
        }
        return new Headers(messageId: $mid, text: $text);
    }

    public function content(): Content
    {
        // ProposalMail と同じ blade を流用。本文 plain text を表示するだけ。
        return new Content(view: 'emails.proposal');
    }

    public function attachments(): array
    {
        return collect($this->uploadedFiles)
            ->map(function ($f) {
                if ($f instanceof UploadedFile) {
                    return Attachment::fromPath($f->getRealPath())
                        ->as($f->getClientOriginalName())
                        ->withMime($f->getMimeType() ?? 'application/octet-stream');
                }
                if (is_string($f) && is_file($f)) {
                    return Attachment::fromPath($f);
                }
                return null;
            })
            ->filter()
            ->all();
    }
}
