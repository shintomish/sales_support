<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 朝の日次レポート Mailable
 */
class DailyReport extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<string,mixed> $data DailyReportBuilder::build() の戻り値
     */
    public function __construct(public readonly array $data) {}

    public function envelope(): Envelope
    {
        $date = $this->data['target_date'] ?? '';
        return new Envelope(
            subject: sprintf('【日次レポート】%s の動き / 要対応 %d件', $date, $this->data['total_action_items'] ?? 0),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mails.daily_report',
            text: 'mails.daily_report_text',
            with: ['data' => $this->data],
        );
    }
}
