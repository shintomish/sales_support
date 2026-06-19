<?php

namespace App\Services;

use App\Mail\DeliveryMail;
use App\Mail\ProposalMail;
use App\Models\DeliveryAddress;
use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\EmailBodyTemplate;
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
     * @param array{project_mail_id: ?int, subject: string, body: string, exclude_address_ids?: array<int>} $data
     */
    public function createCampaign(array $data): DeliveryCampaign
    {
        $excludeIds = $data['exclude_address_ids'] ?? [];
        $totalCount = DeliveryAddress::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->when(!empty($excludeIds), fn($q) => $q->whereNotIn('id', $excludeIds))
            ->count();

        $hasEngineer = !empty($data['engineer_mail_source_id']);

        $projectMailId = !$hasEngineer ? ($data['project_mail_id'] ?? null) : null;

        // delivery_type: フォームで明示選択された値を優先。無ければ紐づき有無から推測。
        $deliveryType = $data['delivery_type'] ?? null;
        if (!$deliveryType) {
            $deliveryType = $hasEngineer ? 'engineer' : ($projectMailId ? 'project' : null);
        }

        return DeliveryCampaign::create([
            'tenant_id'               => $this->tenantId,
            'send_type'               => 'delivery',
            'delivery_type'           => $deliveryType,
            'delivery_purpose'        => $data['delivery_purpose'] ?? 'standard',
            'project_mail_id'         => $projectMailId,
            'engineer_mail_source_id' => $hasEngineer ? $data['engineer_mail_source_id'] : null,
            // 紐づき案件/技術者がない時のみ、手動入力された入手元アドレスを記録
            'source_email'            => (!$hasEngineer && !$projectMailId) ? ($data['source_email'] ?? null) : null,
            'user_id'                 => $this->userId,
            'subject'                 => $data['subject'],
            'body'                    => $data['body'],
            'total_count'             => $totalCount,
            'success_count'           => 0,
            'failed_count'            => 0,
            'sent_at'                 => now(),
        ]);
    }

    /**
     * キャンペーンの一括送信を実行する（バックグラウンド想定）
     *
     * MAIL_DELIVERY_TEST_TO が設定されている場合は全件そのアドレスへリダイレクト。
     *
     * @param string[]    $attachmentPaths 一時保存済みファイルの絶対パス一覧
     * @param array<int>  $excludeIds      今回送信から除外する delivery_address_id (元請けドメイン警告での選択除外用)
     */
    public function sendCampaign(DeliveryCampaign $campaign, array $attachmentPaths = [], array $excludeIds = []): void
    {
        $testTo      = config('mail.delivery_test_to');
        // Reply-To をログインユーザー(営業担当)個人のアドレスにして、客先返信が outsource@ ではなく
        // 担当者宛に届くようにする (2026-05-27 fix)。User の email が未設定の場合のみ outsource@ にフォールバック。
        $user        = \App\Models\User::find($this->userId);
        $senderEmail = $user?->email ?: (config('mail.from.address') ?? '');
        // ユーザ別の From 表示名 (メール署名設定)
        $fromDisplayName = \App\Models\EmailBodyTemplate::where('user_id', $this->userId)->value('sender_display_name') ?: null;

        $addresses = DeliveryAddress::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->when(!empty($excludeIds), fn($q) => $q->whereNotIn('id', $excludeIds))
            ->orderBy('id')
            ->get();

        Cache::put("campaign_sending_{$campaign->id}", true, now()->addHours(2));

        foreach ($addresses as $index => $address) {
            // SES レート制限対策: 14件/秒 → 100ms間隔（余裕込み）
            if ($index > 0) {
                usleep(100_000);
            }

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
                        fromDisplayName: $fromDisplayName,
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

    /**
     * 単一宛先への提案メール送信 (proposal / matching_proposal / engineer_proposal 等)。
     *
     * 1 DeliveryCampaign + 1 DeliverySendHistory を作り、ProposalMail で送信、
     * sent/failed の集計と添付一時ファイルのクリーンアップまで行う共通処理。
     * 旧実装は同パターンが 6+ controller method に丸ごとコピペされていた (docs/730 §Medium #12)。
     *
     * @param array{
     *   send_type: string,
     *   to: string, to_name?: string|null,
     *   subject: string, body: string,
     *   attachment_paths?: array<string>,
     *   sender_name: string, sender_email: string, from_display_name?: string|null,
     *   project_mail_id?: int|null,
     *   engineer_mail_source_id?: int|null,
     *   engineer_id?: int|null,
     *   public_project_id?: int|null,
     *   log_context?: array<string,mixed>,
     * } $params
     *
     * @return array{campaign: DeliveryCampaign, success: bool, history: DeliverySendHistory, message_id: string}
     */
    public function sendSingleProposal(array $params): array
    {
        $attachmentPaths = $params['attachment_paths'] ?? [];
        $campaign = DeliveryCampaign::create([
            'tenant_id'               => $this->tenantId,
            'send_type'               => $params['send_type'],
            'project_mail_id'         => $params['project_mail_id'] ?? null,
            'engineer_mail_source_id' => $params['engineer_mail_source_id'] ?? null,
            'user_id'                 => $this->userId,
            'subject'                 => $params['subject'],
            'body'                    => $params['body'],
            'total_count'             => 1,
            'success_count'           => 0,
            'failed_count'            => 0,
            'sent_at'                 => now(),
        ]);

        $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
        $logCtx    = $params['log_context'] ?? [];
        $logCtxStr = $logCtx ? ' ' . json_encode($logCtx, JSON_UNESCAPED_UNICODE) : '';

        try {
            Mail::to($params['to'])->send(new ProposalMail(
                $params['subject'],
                $params['body'],
                $params['sender_name'],
                $params['sender_email'],
                $attachmentPaths,
                $messageId,
                fromDisplayName: $params['from_display_name'] ?? null,
            ));

            $history = DeliverySendHistory::create([
                'tenant_id'         => $this->tenantId,
                'campaign_id'       => $campaign->id,
                'engineer_id'       => $params['engineer_id']        ?? null,
                'public_project_id' => $params['public_project_id']  ?? null,
                'email'             => $params['to'],
                'name'              => $params['to_name']            ?? null,
                'status'            => 'sent',
                'ses_message_id'    => $messageId,
            ]);
            $campaign->update(['success_count' => 1]);

            Log::info("[Proposal] {$params['send_type']} sent to={$params['to']}{$logCtxStr}");

            return ['campaign' => $campaign, 'success' => true, 'history' => $history, 'message_id' => $messageId];

        } catch (\Throwable $e) {
            $history = DeliverySendHistory::create([
                'tenant_id'         => $this->tenantId,
                'campaign_id'       => $campaign->id,
                'engineer_id'       => $params['engineer_id']        ?? null,
                'public_project_id' => $params['public_project_id']  ?? null,
                'email'             => $params['to'],
                'name'              => $params['to_name']            ?? null,
                'status'            => 'failed',
                'ses_message_id'    => $messageId,
                'error_message'     => $e->getMessage(),
            ]);
            $campaign->update(['failed_count' => 1]);

            Log::error("[Proposal] {$params['send_type']} failed to={$params['to']}{$logCtxStr}: " . $e->getMessage());

            return ['campaign' => $campaign, 'success' => false, 'history' => $history, 'message_id' => $messageId];

        } finally {
            // 添付一時ファイルのクリーンアップ
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

    /**
     * 複数宛先の bulk 提案送信 (send_type='bulk' / 'engineer_proposal_bulk')。
     *
     * 1 DeliveryCampaign + N DeliverySendHistory を作り、recipients[] を順に送信する。
     * sendSingleProposal を内部で再利用すると campaign が宛先数だけ作られてしまうため、
     * ここでは inline で 1 campaign + N history を構築する。
     *
     * @param array{
     *   send_type: string,
     *   recipients: array<int, array{to: string, name?: string|null}>,
     *   subject: string, body: string,
     *   attachment_paths?: array<string>,
     *   sender_name: string, sender_email: string, from_display_name?: string|null,
     *   project_mail_id?: int|null,
     *   engineer_mail_source_id?: int|null,
     *   log_context?: array<string,mixed>,
     * } $params
     *
     * @return array{campaign: DeliveryCampaign, sent: int, failed: array<int,string>}
     */
    public function sendBulkProposal(array $params): array
    {
        $recipients      = $params['recipients'];
        $attachmentPaths = $params['attachment_paths'] ?? [];
        $logCtx          = $params['log_context'] ?? [];
        $logCtxStr       = $logCtx ? ' ' . json_encode($logCtx, JSON_UNESCAPED_UNICODE) : '';

        $campaign = DeliveryCampaign::create([
            'tenant_id'               => $this->tenantId,
            'send_type'               => $params['send_type'],
            'project_mail_id'         => $params['project_mail_id'] ?? null,
            'engineer_mail_source_id' => $params['engineer_mail_source_id'] ?? null,
            'user_id'                 => $this->userId,
            'subject'                 => $params['subject'],
            'body'                    => $params['body'],
            'total_count'             => count($recipients),
            'success_count'           => 0,
            'failed_count'            => 0,
            'sent_at'                 => now(),
        ]);

        $sent   = 0;
        $failed = [];

        foreach ($recipients as $r) {
            $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';
            try {
                Mail::to($r['to'])->send(new ProposalMail(
                    $params['subject'],
                    $params['body'],
                    $params['sender_name'],
                    $params['sender_email'],
                    $attachmentPaths,
                    $messageId,
                    fromDisplayName: $params['from_display_name'] ?? null,
                ));
                DeliverySendHistory::create([
                    'tenant_id'      => $this->tenantId,
                    'campaign_id'    => $campaign->id,
                    'email'          => $r['to'],
                    'name'           => $r['name'] ?? null,
                    'status'         => 'sent',
                    'ses_message_id' => $messageId,
                ]);
                $sent++;
            } catch (\Throwable $e) {
                DeliverySendHistory::create([
                    'tenant_id'      => $this->tenantId,
                    'campaign_id'    => $campaign->id,
                    'email'          => $r['to'],
                    'name'           => $r['name'] ?? null,
                    'status'         => 'failed',
                    'ses_message_id' => $messageId,
                    'error_message'  => $e->getMessage(),
                ]);
                $failed[] = $r['to'];
                Log::error("[ProposalBulk] {$params['send_type']} failed to={$r['to']}{$logCtxStr}: " . $e->getMessage());
            }
        }

        $campaign->update(['success_count' => $sent, 'failed_count' => count($failed)]);
        Log::info("[ProposalBulk] {$params['send_type']} sent={$sent} failed=" . count($failed) . $logCtxStr);

        // 添付一時ファイルのクリーンアップ
        foreach ($attachmentPaths as $path) {
            if (is_file($path)) @unlink($path);
        }
        if ($attachmentPaths) {
            $dir = dirname($attachmentPaths[0]);
            if (is_dir($dir) && count(array_diff(scandir($dir), ['.', '..'])) === 0) {
                @rmdir($dir);
            }
        }

        return ['campaign' => $campaign, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * 単一の送信履歴を再送する。
     *  - 元 history の resent_at を now に更新
     *  - 同 campaign 内に新しい history 行を作成（parent_history_id で紐付け）
     *  - キャンペーンの total_count / success_count / failed_count を加算
     */
    public function resendHistory(DeliverySendHistory $history): DeliverySendHistory
    {
        $campaign    = $history->campaign;
        $testTo      = config('mail.delivery_test_to');
        $senderEmail = config('mail.from.address') ?? '';
        $fromDisplayName = \App\Models\EmailBodyTemplate::where('user_id', $this->userId)->value('sender_display_name') ?: null;

        $toEmail   = $testTo ?: $history->email;
        $messageId = '<' . Str::uuid() . '@aizen-sol.co.jp>';

        $personalizedBody = str_replace('<%Name%>', $history->name ?? '', $campaign->body);

        // 配信停止リンク（DeliveryAddress が紐付いていれば）
        if ($history->delivery_address_id) {
            $address = DeliveryAddress::find($history->delivery_address_id);
            if ($address) {
                $unsubscribeUrl   = url('/unsubscribe/' . $address->unsubscribe_token);
                $personalizedBody .= "\n\n---\n配信停止をご希望の場合は、こちらからお手続きください。\n{$unsubscribeUrl}";
            }
        }

        try {
            Mail::to($toEmail)->send(
                new DeliveryMail(
                    mailSubject:     $campaign->subject,
                    body:            $personalizedBody,
                    senderName:      $this->senderName,
                    senderEmail:     $senderEmail,
                    messageId:       $messageId,
                    fromDisplayName: $fromDisplayName,
                )
            );

            $newHistory = DeliverySendHistory::create([
                'tenant_id'           => $this->tenantId,
                'campaign_id'         => $campaign->id,
                'delivery_address_id' => $history->delivery_address_id,
                'engineer_id'         => $history->engineer_id,
                'public_project_id'   => $history->public_project_id,
                'email'               => $history->email,
                'name'                => $history->name,
                'status'              => 'sent',
                'ses_message_id'      => $messageId,
                'parent_history_id'   => $history->id,
            ]);

            $history->update(['resent_at' => now()]);
            $campaign->increment('total_count');
            $campaign->increment('success_count');

            Log::info("[DeliveryCampaign] resend success campaign_id={$campaign->id} history_id={$history->id} new_history_id={$newHistory->id}");

            return $newHistory;
        } catch (\Throwable $e) {
            $newHistory = DeliverySendHistory::create([
                'tenant_id'           => $this->tenantId,
                'campaign_id'         => $campaign->id,
                'delivery_address_id' => $history->delivery_address_id,
                'engineer_id'         => $history->engineer_id,
                'public_project_id'   => $history->public_project_id,
                'email'               => $history->email,
                'name'                => $history->name,
                'status'              => 'failed',
                'ses_message_id'      => $messageId,
                'error_message'       => $e->getMessage(),
                'parent_history_id'   => $history->id,
            ]);

            $history->update(['resent_at' => now()]);
            $campaign->increment('total_count');
            $campaign->increment('failed_count');

            Log::error("[DeliveryCampaign] resend failed campaign_id={$campaign->id} history_id={$history->id}: " . $e->getMessage());

            return $newHistory;
        }
    }
}
