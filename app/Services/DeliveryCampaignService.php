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

        $hasEngineer = !empty($data['engineer_mail_source_id']);

        return DeliveryCampaign::create([
            'tenant_id'               => $this->tenantId,
            'send_type'               => 'delivery',
            'project_mail_id'         => !$hasEngineer ? ($data['project_mail_id'] ?? null) : null,
            'engineer_mail_source_id' => $hasEngineer ? $data['engineer_mail_source_id'] : null,
            'user_id'                 => $this->userId,
            'subject'                 => $data['subject'],
            'body'                    => $data['body'],
            'total_count'             => $totalCount,
            'success_count'           => 0,
            'failed_count'            => 0,
            'sent_at'                 => now('UTC'),
        ]);
    }

    /**
     * キャンペーンの一括送信を実行する（バックグラウンド想定）
     *
     * MAIL_DELIVERY_TEST_TO が設定されている場合は全件そのアドレスへリダイレクト。
     *
     * @param string[] $attachmentPaths 一時保存済みファイルの絶対パス一覧
     */
    public function sendCampaign(DeliveryCampaign $campaign, array $attachmentPaths = []): void
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

            // <%Name%> を配信先の名前に置換
            $personalizedBody = str_replace('<%Name%>', $address->name ?? '', $campaign->body);

            // 配信停止リンクを末尾に追加
            $unsubscribeUrl   = url('/unsubscribe/' . $address->unsubscribe_token);
            $personalizedBody .= "\n\n---\n配信停止をご希望の場合は、こちらからお手続きください。\n{$unsubscribeUrl}";

            try {
                Mail::to($toEmail)->send(
                    new DeliveryMail(
                        mailSubject:     $campaign->subject,
                        body:            $personalizedBody,
                        senderName:      $this->senderName,
                        senderEmail:     $senderEmail,
                        messageId:       $messageId,
                        attachmentPaths: $attachmentPaths,
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

        // 一時ファイルを削除
        foreach ($attachmentPaths as $path) {
            if (is_file($path)) @unlink($path);
        }
        if ($attachmentPaths) {
            $dir = dirname($attachmentPaths[0]);
            if (is_dir($dir) && count(array_diff(scandir($dir), ['.', '..'])) === 0) {
                @rmdir($dir);
            }
        }
    }
}
