<?php

namespace Tests\Feature\Services;

use App\Models\DeliveryAddress;
use App\Services\BounceSuppressionService;
use Tests\TestCase;

/**
 * ハードバウンス自動抑制のテスト。
 *  - parse(): DSN 解析が hard(5.x.x)/soft(4.x.x)/複数宛先/非DSN を正しく分類する
 *  - suppressHardBounces(): enforce/log-only・リスト外・別テナント・既停止 のガード
 */
class BounceSuppressionServiceTest extends TestCase
{
    private BounceSuppressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BounceSuppressionService();
        $this->actingAsUser();
    }

    private function hardDsn(string $recipient = 'dead@example.com'): string
    {
        return <<<EOT
        From: MAILER-DAEMON@ap-northeast-1.amazonses.com
        To: outsource@aizen-sol.co.jp
        Subject: Delivery Status Notification (Failure)
        Content-Type: multipart/report; report-type=delivery-status; boundary="b"

        --b
        Content-Type: text/plain

        An error occurred while trying to deliver your message.

        --b
        Content-Type: message/delivery-status

        Reporting-MTA: dns; a8-12.smtp-out.amazonses.com

        Final-Recipient: rfc822; {$recipient}
        Action: failed
        Status: 5.1.1
        Diagnostic-Code: smtp; 550 5.1.1 user unknown
        --b--
        EOT;
    }

    private function softDsn(string $recipient = 'busy@example.com'): string
    {
        return <<<EOT
        From: MAILER-DAEMON@mss-g2-140.kagoya.net
        Subject: Undelivered Mail Returned to Sender
        Content-Type: message/delivery-status

        Final-Recipient: rfc822; {$recipient}
        Action: failed
        Status: 4.2.2
        Diagnostic-Code: smtp; 452 4.2.2 mailbox full
        EOT;
    }

    // ---- parse() ----

    public function test_parse_extracts_hard_bounce(): void
    {
        $rows = $this->service->parse($this->hardDsn('dead@example.com'));
        $this->assertCount(1, $rows);
        $this->assertSame('dead@example.com', $rows[0]['email']);
        $this->assertSame('5.1.1', $rows[0]['status']);
        $this->assertTrue($rows[0]['hard']);
    }

    public function test_parse_marks_soft_bounce_as_not_hard(): void
    {
        $rows = $this->service->parse($this->softDsn('busy@example.com'));
        $this->assertCount(1, $rows);
        $this->assertSame('4.2.2', $rows[0]['status']);
        $this->assertFalse($rows[0]['hard']);
    }

    public function test_parse_handles_multi_recipient_mixed(): void
    {
        $raw = <<<EOT
        Content-Type: message/delivery-status

        Final-Recipient: rfc822; dead@example.com
        Action: failed
        Status: 5.1.1

        Final-Recipient: rfc822; ok@example.com
        Action: delivered
        Status: 2.0.0
        EOT;
        $rows = collect($this->service->parse($raw))->keyBy('email');
        $this->assertTrue($rows['dead@example.com']['hard']);
        $this->assertFalse($rows['ok@example.com']['hard']);
    }

    public function test_parse_non_dsn_returns_empty(): void
    {
        $raw = "From: promo@shop.example\nSubject: [spam] 90%OFF セール\n\n本文です";
        $this->assertSame([], $this->service->parse($raw));
    }

    // ---- suppressHardBounces() ----

    public function test_enforce_disables_hard_bounced_address(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $addr = $this->makeAddress('dead@example.com');

        $suppressed = $this->service->suppressHardBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id);

        $this->assertSame(['dead@example.com'], $suppressed);
        $addr->refresh();
        $this->assertFalse($addr->is_active);
        $this->assertSame('hard_bounce', $addr->unsubscribe_reason);
        $this->assertNotNull($addr->unsubscribed_at);
    }

    public function test_log_only_detects_but_does_not_mutate(): void
    {
        config(['services.bounce_suppression.enforce' => false]);
        $addr = $this->makeAddress('dead@example.com');

        $suppressed = $this->service->suppressHardBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id);

        $this->assertSame(['dead@example.com'], $suppressed); // 検出はする
        $addr->refresh();
        $this->assertTrue($addr->is_active); // が、無効化はしない
    }

    public function test_soft_bounce_is_not_suppressed(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $addr = $this->makeAddress('busy@example.com');

        $suppressed = $this->service->suppressHardBounces($this->softDsn('busy@example.com'), $this->authUser->tenant_id);

        $this->assertSame([], $suppressed);
        $addr->refresh();
        $this->assertTrue($addr->is_active);
    }

    public function test_address_not_in_list_is_ignored(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $suppressed = $this->service->suppressHardBounces($this->hardDsn('unknown@nowhere.example'), $this->authUser->tenant_id);
        $this->assertSame([], $suppressed);
    }

    public function test_other_tenant_address_is_not_touched(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $other = $this->createUserInAnotherTenant();
        $addr = DeliveryAddress::create([
            'tenant_id' => $other->tenant_id,
            'email'     => 'dead@example.com',
            'is_active' => true,
        ]);

        // 自テナント宛に処理しても別テナントのアドレスは触らない
        $suppressed = $this->service->suppressHardBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id);

        $this->assertSame([], $suppressed);
        $addr->refresh();
        $this->assertTrue($addr->is_active);
    }

    public function test_already_inactive_address_is_skipped(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $addr = DeliveryAddress::create([
            'tenant_id'          => $this->authUser->tenant_id,
            'email'              => 'dead@example.com',
            'is_active'          => false,
            'unsubscribe_reason' => 'operator_disabled',
        ]);

        $suppressed = $this->service->suppressHardBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id);

        $this->assertSame([], $suppressed);
        $addr->refresh();
        // 既存の停止理由を上書きしない
        $this->assertSame('operator_disabled', $addr->unsubscribe_reason);
    }

    public function test_email_match_is_case_insensitive(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $addr = $this->makeAddress('Dead@Example.com');

        $suppressed = $this->service->suppressHardBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id);

        $this->assertCount(1, $suppressed);
        $addr->refresh();
        $this->assertFalse($addr->is_active);
    }

    private function makeAddress(string $email): DeliveryAddress
    {
        return DeliveryAddress::create([
            'tenant_id' => $this->authUser->tenant_id,
            'email'     => $email,
            'is_active' => true,
        ]);
    }
}
