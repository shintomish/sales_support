<?php

namespace App\Services;

use App\Mail\DeliveryMail;
use App\Models\DeliveryAddress;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DeliveryCampaignService
{
    public function __construct(
        private readonly int $tenantId,
        private readonly int $userId,
    ) {}

    /**
     * 配信先リスト全員に一括送信しキャンペーンを返す
     *
     * @param array{project_mail_id: ?int, subject: string, body: string} $data
     */
    public function send(array $data): DeliveryCampaign
    {
        $senderName  = auth()->user()->name  ?? '';
        $senderEmail = $this->replyToAddress();

        $addresses = DeliveryAddress::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $campaign = DeliveryCampaign::create([
            'tenant_id'       => $this->tenantId,
            'project_mail_id' => $data['project_mail_id'] ?? null,
            'user_id'         => $this->userId,
            'subject'         => $data['subject'],
            'body'            => $data['body'],
            'total_count'     => $addresses->count(),
            'success_count'   => 0,
            'failed_count'    => 0,
            'sent_at'         => now(),
        ]);

        $successCount = 0;
        $failedCount  = 0;

        foreach ($addresses as $address) {
            // 返信紐づけ用にMessage-IDを事前生成
            $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';

            try {
                Mail::to($address->email)->send(
                    new DeliveryMail(
                        mailSubject: $data['subject'],
                        body:        $data['body'],
                        senderName:  $senderName,
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

                $successCount++;
                Log::info("[DeliveryCampaign] campaign_id={$campaign->id} sent to={$address->email}");

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

                $failedCount++;
                Log::error("[DeliveryCampaign] campaign_id={$campaign->id} failed to={$address->email}: " . $e->getMessage());
            }
        }

        $campaign->update([
            'success_count' => $successCount,
            'failed_count'  => $failedCount,
        ]);

        return $campaign->fresh();
    }

    private function replyToAddress(): string
    {
        return config('mail.from.address') ?? '';
    }
}
