<?php

namespace App\Services;

use App\Mail\DeliveryMail;
use App\Models\DeliveryAddress;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DeliveryCampaignService
{
    public function __construct(
        private readonly int    $tenantId,
        private readonly int    $userId,
        private readonly string $senderName = '',
    ) {}

    /**
     * キャンペーンレコードを作成して返す（送信はしない）
     *
     * @param array{project_mail_id: ?int, subject: string, body: string} $data
     */
    public function createCampaign(array $data): DeliveryCampaign
    {
        $totalCount = DeliveryAddress::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->count();

        return DeliveryCampaign::create([
            'tenant_id'       => $this->tenantId,
            'project_mail_id' => $data['project_mail_id'] ?? null,
            'user_id'         => $this->userId,
            'subject'         => $data['subject'],
            'body'            => $data['body'],
            'total_count'     => $totalCount,
            'success_count'   => 0,
            'failed_count'    => 0,
            'sent_at'         => now(),
        ]);
    }

    /**
     * キャンペーンの一括送信を実行する（バックグラウンド想定）
     *
     * MAIL_DELIVERY_TEST_TO が設定されている場合は全件そのアドレスへリダイレクト。
     */
    public function sendCampaign(DeliveryCampaign $campaign): void
    {
        $testTo      = env('MAIL_DELIVERY_TEST_TO');
        $senderEmail = config('mail.from.address') ?? '';

        $addresses = DeliveryAddress::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        Cache::put("campaign_sending_{$campaign->id}", true, now()->addHours(2));

        foreach ($addresses as $address) {
            $toEmail   = $testTo ?: $address->email;
            $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';

            try {
                Mail::to($toEmail)->send(
                    new DeliveryMail(
                        mailSubject: $campaign->subject,
                        body:        $campaign->body,
                        senderName:  $this->senderName,
                        senderEmail: $senderEmail,
                        messageId:   $messageId,
                    )
                );

                DeliverySendHistory::create([
                    'tenant_id'           => $this->tenantId,
                    'campaign_id'         => $campaign->id,
                    'delivery_address_id' => $address->id,
                    'email'               => $address->email,
                    'name'                => $address->name,
                    'status'              => 'sent',
                    'ses_message_id'      => $messageId,
                ]);

                $campaign->increment('success_count');
                Log::info("[DeliveryCampaign] campaign_id={$campaign->id} sent to={$address->email}" . ($testTo ? " (test→{$testTo})" : ''));

            } catch (\Throwable $e) {
                DeliverySendHistory::create([
                    'tenant_id'           => $this->tenantId,
                    'campaign_id'         => $campaign->id,
                    'delivery_address_id' => $address->id,
                    'email'               => $address->email,
                    'name'                => $address->name,
                    'status'              => 'failed',
                    'ses_message_id'      => $messageId,
                    'error_message'       => $e->getMessage(),
                ]);

                $campaign->increment('failed_count');
                Log::error("[DeliveryCampaign] campaign_id={$campaign->id} failed to={$address->email}: " . $e->getMessage());
            }
        }

        Cache::forget("campaign_sending_{$campaign->id}");
    }
}
