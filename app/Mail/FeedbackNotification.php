<?php

namespace App\Mail;

use App\Models\FeedbackReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 社内バグ・要望フィードバック通知メール
 *
 * /settings/feedback からの投稿時に FEEDBACK_NOTIFY_TO（既定: y-shintomi@aizen-sol.co.jp）に送信。
 */
class FeedbackNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly FeedbackReport $feedback,
        public readonly ?string $tenantName,
        public readonly ?string $userName,
        public readonly ?string $userEmail,
    ) {}

    public function envelope(): Envelope
    {
        $typeLabel = match ($this->feedback->type) {
            FeedbackReport::TYPE_BUG     => 'バグ',
            FeedbackReport::TYPE_REQUEST => '要望',
            default                      => 'その他',
        };
        $tenant = $this->tenantName ?: "tenant_id={$this->feedback->tenant_id}";
        $user   = $this->userName ?: 'unknown';

        return new Envelope(
            subject: sprintf('【ご意見】[%s] %s - %s/%s', $typeLabel, $this->feedback->subject, $tenant, $user),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mails.feedback_notification',
            text: 'mails.feedback_notification_text',
            with: [
                'feedback'    => $this->feedback,
                'tenantName'  => $this->tenantName,
                'userName'    => $this->userName,
                'userEmail'   => $this->userEmail,
            ],
        );
    }
}
