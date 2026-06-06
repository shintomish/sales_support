<?php

namespace Tests\Feature\Services;

use App\Models\DeliveryAddress;
use App\Services\BounceSuppressionService;
use Tests\TestCase;

/**
 * バウンス自動抑制のテスト。
 *  - parse(): hard(5.x.x) / expired(4.x.x give-up) / delayed(再試行中) / 非DSN の分類
 *  - suppressBounces(): hard 即時停止 / expired 累積閾値 / log-only / リスト外 / 別テナント / 既停止
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
        Subject: Delivery Status Notification (Failure)
        Content-Type: message/delivery-status

        Final-Recipient: rfc822; {$recipient}
        Action: failed
        Status: 5.1.1
        Diagnostic-Code: smtp; 550 5.1.1 user unknown
        EOT;
    }

    /** SES が 14時間試行して諦めた 4.4.7(Message expired)。実バウンスの大半がこれ。 */
    private function expiredDsn(string $recipient = 'gone@example.com'): string
    {
        return <<<EOT
        From: MAILER-DAEMON@ap-northeast-1.amazonses.com
        Subject: Delivery Status Notification (Failure)
        Content-Type: message/delivery-status

        Final-Recipient: rfc822; {$recipient}
        Action: failed
        Status: 4.4.7
        Diagnostic-Code: smtp; 550 4.4.7 Message expired: unable to deliver in 840 minutes.<421 4.4.0 Unable to lookup DNS>
        EOT;
    }

    /** まだ再試行中(最終通知でない)。停止対象にしない。 */
    private function delayedDsn(string $recipient = 'slow@example.com'): string
    {
        return <<<EOT
        From: MAILER-DAEMON@ap-northeast-1.amazonses.com
        Subject: Delivery Status Notification (Delay)
        Content-Type: message/delivery-status

        Final-Recipient: rfc822; {$recipient}
        Action: delayed
        Status: 4.4.7
        EOT;
    }

    // ---- parse() ----

    public function test_parse_classifies_hard(): void
    {
        $r = $this->service->parse($this->hardDsn())[0];
        $this->assertTrue($r['hard']);
        $this->assertFalse($r['expired']);
    }

    public function test_parse_classifies_expired(): void
    {
        $r = $this->service->parse($this->expiredDsn())[0];
        $this->assertFalse($r['hard']);
        $this->assertTrue($r['expired']);
        $this->assertSame('4.4.7', $r['status']);
    }

    public function test_parse_delayed_is_neither(): void
    {
        $r = $this->service->parse($this->delayedDsn())[0];
        $this->assertFalse($r['hard']);
        $this->assertFalse($r['expired']);
    }

    public function test_parse_non_dsn_returns_empty(): void
    {
        $this->assertSame([], $this->service->parse("From: x@y.z\nSubject: [spam] sale\n\nhi"));
    }

    // ---- hard ----

    public function test_hard_disables_immediately_when_enforce(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $addr = $this->makeAddress('dead@example.com');

        $out = $this->service->suppressBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id);

        $this->assertSame(['dead@example.com'], $out);
        $addr->refresh();
        $this->assertFalse($addr->is_active);
        $this->assertSame('hard_bounce', $addr->unsubscribe_reason);
    }

    public function test_log_only_does_not_disable_hard(): void
    {
        config(['services.bounce_suppression.enforce' => false]);
        $addr = $this->makeAddress('dead@example.com');

        $out = $this->service->suppressBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id);

        $this->assertSame(['dead@example.com'], $out); // 検出はする
        $addr->refresh();
        $this->assertTrue($addr->is_active); // 無効化はしない
        $this->assertNotNull($addr->last_bounce_at); // メタは記録
    }

    // ---- expired (累積閾値) ----

    public function test_expired_single_below_threshold_does_not_disable(): void
    {
        config(['services.bounce_suppression.enforce' => true, 'services.bounce_suppression.expired_threshold' => 2]);
        $addr = $this->makeAddress('gone@example.com');

        $out = $this->service->suppressBounces($this->expiredDsn('gone@example.com'), $this->authUser->tenant_id);

        $this->assertSame([], $out); // 閾値未満
        $addr->refresh();
        $this->assertTrue($addr->is_active);
        $this->assertSame(1, $addr->soft_bounce_count);
    }

    public function test_expired_reaching_threshold_disables(): void
    {
        config(['services.bounce_suppression.enforce' => true, 'services.bounce_suppression.expired_threshold' => 2]);
        $addr = $this->makeAddress('gone@example.com');
        $addr->update(['soft_bounce_count' => 1]); // 既に1回期限切れ済み

        $out = $this->service->suppressBounces($this->expiredDsn('gone@example.com'), $this->authUser->tenant_id);

        $this->assertSame(['gone@example.com'], $out);
        $addr->refresh();
        $this->assertFalse($addr->is_active);
        $this->assertSame('expired_bounce', $addr->unsubscribe_reason);
        $this->assertSame(2, $addr->soft_bounce_count);
    }

    public function test_expired_threshold_one_disables_on_first(): void
    {
        config(['services.bounce_suppression.enforce' => true, 'services.bounce_suppression.expired_threshold' => 1]);
        $addr = $this->makeAddress('gone@example.com');

        $out = $this->service->suppressBounces($this->expiredDsn('gone@example.com'), $this->authUser->tenant_id);

        $this->assertSame(['gone@example.com'], $out);
        $addr->refresh();
        $this->assertFalse($addr->is_active);
    }

    public function test_log_only_increments_count_but_does_not_disable_at_threshold(): void
    {
        config(['services.bounce_suppression.enforce' => false, 'services.bounce_suppression.expired_threshold' => 1]);
        $addr = $this->makeAddress('gone@example.com');

        $out = $this->service->suppressBounces($this->expiredDsn('gone@example.com'), $this->authUser->tenant_id);

        $this->assertSame(['gone@example.com'], $out); // 閾値到達を検出
        $addr->refresh();
        $this->assertTrue($addr->is_active);       // が、無効化しない
        $this->assertSame(1, $addr->soft_bounce_count); // カウントは進む
    }

    // ---- ガード ----

    public function test_address_not_in_list_is_ignored(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $this->assertSame([], $this->service->suppressBounces($this->hardDsn('x@nowhere.example'), $this->authUser->tenant_id));
    }

    public function test_other_tenant_is_not_touched(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $other = $this->createUserInAnotherTenant();
        $addr = DeliveryAddress::create(['tenant_id' => $other->tenant_id, 'email' => 'dead@example.com', 'is_active' => true]);

        $this->assertSame([], $this->service->suppressBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id));
        $addr->refresh();
        $this->assertTrue($addr->is_active);
    }

    public function test_already_inactive_is_skipped(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $addr = DeliveryAddress::create([
            'tenant_id' => $this->authUser->tenant_id, 'email' => 'dead@example.com',
            'is_active' => false, 'unsubscribe_reason' => 'operator_disabled',
        ]);

        $this->assertSame([], $this->service->suppressBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id));
        $addr->refresh();
        $this->assertSame('operator_disabled', $addr->unsubscribe_reason);
    }

    public function test_case_insensitive_match(): void
    {
        config(['services.bounce_suppression.enforce' => true]);
        $addr = $this->makeAddress('Dead@Example.com');
        $out = $this->service->suppressBounces($this->hardDsn('dead@example.com'), $this->authUser->tenant_id);
        $this->assertCount(1, $out);
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
