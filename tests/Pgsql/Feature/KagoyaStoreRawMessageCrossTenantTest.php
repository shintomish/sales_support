<?php

namespace Tests\Pgsql\Feature;

use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Tenant;
use App\Services\KagoyaMailService;
use ReflectionMethod;
use Tests\Pgsql\PgsqlTestCase;

/**
 * KagoyaMailService::storeRawMessage の cross-tenant 返信誤紐付け回帰テスト。
 *
 * docs/730 #32 に基づき、reply-linker クエリに tenant_id WHERE を追加した修正の
 * 回帰防護。スケジュールジョブから呼ばれるため Auth context が無く TenantScope が
 * 効かない経路で、別テナントの DSH に誤って reply_email_id を書き込まないことを pin。
 *
 * テスト対象は private な storeRawMessage を ReflectionMethod で呼ぶ。
 */
class KagoyaStoreRawMessageCrossTenantTest extends PgsqlTestCase
{
    public function test_reply_does_not_link_to_other_tenants_dsh_with_same_ses_message_id(): void
    {
        // 2 テナント分の DSH を同じ ses_message_id で用意 (現実には衝突しないが、
        // 仮に LIKE フォールバックで cross-tenant マッチを引き起こす可能性に備える)
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $sharedMessageId = '<msg-shared-12345@example.com>';

        $campaignA = DeliveryCampaign::create([
            'tenant_id' => $tenantA->id,
            'subject'   => 'Test Subject A',
            'body'      => 'Body A',
            'send_type' => 'delivery',
        ]);
        $campaignB = DeliveryCampaign::create([
            'tenant_id' => $tenantB->id,
            'subject'   => 'Test Subject B',
            'body'      => 'Body B',
            'send_type' => 'delivery',
        ]);

        $dshA = DeliverySendHistory::create([
            'tenant_id'      => $tenantA->id,
            'campaign_id'    => $campaignA->id,
            'email'          => 'sender@tenant-a.example.com',
            'ses_message_id' => trim($sharedMessageId, '<>'),
            'status'         => 'sent',
        ]);
        $dshB = DeliverySendHistory::create([
            'tenant_id'      => $tenantB->id,
            'campaign_id'    => $campaignB->id,
            'email'          => 'sender@tenant-b.example.com',
            'ses_message_id' => trim($sharedMessageId, '<>'),
            'status'         => 'sent',
        ]);

        // tenant B 宛の返信メールを Kagoya 取込で処理
        $raw = $this->buildRawReplyMessage(
            from: 'reply-author@tenant-b-external.example.com',
            to:   'outsource@aizen-sol.co.jp',
            subject: 'Re: Test Subject B',
            inReplyTo: $sharedMessageId,
            body: 'This is the reply body.',
        );

        $service = app(KagoyaMailService::class);
        $method = new ReflectionMethod(KagoyaMailService::class, 'storeRawMessage');
        $method->setAccessible(true);
        $stored = $method->invoke($service, $raw, $tenantB->id, 'imap-test-' . uniqid(), null);

        $this->assertTrue($stored);

        // tenant B の DSH が紐付く
        $dshB->refresh();
        $this->assertNotNull($dshB->reply_email_id,
            'tenant B の DSH には reply_email_id がセットされる');

        // tenant A の DSH は触られない (cross-tenant 誤紐付け無し)
        $dshA->refresh();
        $this->assertNull($dshA->reply_email_id,
            'tenant A の DSH は ses_message_id が一致しても tenant_id ガードで除外される');
    }

    /**
     * RFC822 形式の生メールを構築する (storeRawMessage の入力フォーマット)
     */
    private function buildRawReplyMessage(string $from, string $to, string $subject, string $inReplyTo, string $body): string
    {
        return implode("\r\n", [
            "From: {$from}",
            "To: {$to}",
            "Subject: {$subject}",
            "Date: " . now()->format('D, d M Y H:i:s O'),
            "Message-ID: <test-reply-" . uniqid() . "@example.com>",
            "In-Reply-To: {$inReplyTo}",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: 7bit",
            "",
            $body,
            "",
        ]);
    }
}
