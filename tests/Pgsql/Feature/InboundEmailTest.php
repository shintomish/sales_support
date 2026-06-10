<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use App\Models\Tenant;
use App\Services\KagoyaMailService;
use Tests\Pgsql\PgsqlTestCase;

/**
 * SES Inbound 受信パス (B-light) のテスト。
 *
 * - storeRawFromSes: arrived_at = SES 受信時刻 / received_at = Date ヘッダ / gmail_message_id = ses-*
 * - POST /api/v1/inbound/email: 共有シークレット認証と取込
 *
 * 肝は arrived_at が SES 受信時刻になること（Kagoya INBOX 配送遅延を回避し「受信」表示を準リアルタイム化）。
 */
class InboundEmailTest extends PgsqlTestCase
{
    private function buildRaw(string $messageId, string $dateHeader): string
    {
        return implode("\r\n", [
            'From: ENZIAN営業部 <eigyo@enzian.example.com>',
            'To: <outsource@aizen-sol.co.jp>',
            'Subject: SES経路テスト案件',
            "Date: {$dateHeader}",
            "Message-ID: {$messageId}",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 7bit',
            '',
            'Java/Spring Boot の案件です。',
            '',
        ]);
    }

    public function test_ses取込は_arrived_at_を_ses受信時刻にする(): void
    {
        $tenant = Tenant::factory()->create();
        config(['services.inbound.tenant_id' => $tenant->id]);
        $svc = app(KagoyaMailService::class);

        // Date ヘッダ（送信時刻）= 3時間前、SES 受信時刻 = ほぼ今（配送遅延ゼロを模擬）
        $sentAt = now()->subHours(3);
        $sesReceivedAt = now()->subSeconds(1);

        $stored = $svc->storeRawFromSes(
            $this->buildRaw('<ses-1@enzian.example.com>', $sentAt->format('D, d M Y H:i:s O')),
            '0abc-ses-msg-id',
            $sesReceivedAt->toIso8601String(),
        );

        $this->assertTrue($stored);
        $row = Email::where('tenant_id', $tenant->id)
            ->where('rfc_message_id', 'ses-1@enzian.example.com')->first();

        $this->assertSame('ses-0abc-ses-msg-id', $row->gmail_message_id);
        // received_at は Date ヘッダ（送信時刻）優先
        $this->assertEqualsWithDelta($sentAt->timestamp, $row->received_at->timestamp, 5);
        // arrived_at は SES 受信時刻（準リアルタイム）→ 配送遅延を回避できている
        $this->assertEqualsWithDelta($sesReceivedAt->timestamp, $row->arrived_at->timestamp, 5);
    }

    public function test_エンドポイントは共有シークレットを検証する(): void
    {
        config(['services.inbound.secret' => 'test-secret-xyz']);

        $payload = [
            'ses_message_id' => 'msg-deny',
            'received_at'    => now()->toIso8601String(),
            'raw_base64'     => base64_encode($this->buildRaw('<deny@enzian.example.com>', now()->format('D, d M Y H:i:s O'))),
        ];

        // シークレット無し → 401
        $this->postJson('/api/v1/inbound/email', $payload)
            ->assertStatus(401);

        // 誤ったシークレット → 401
        $this->withHeader('X-Inbound-Secret', 'wrong')
            ->postJson('/api/v1/inbound/email', $payload)
            ->assertStatus(401);

        $this->assertDatabaseMissing('emails', ['rfc_message_id' => 'deny@enzian.example.com']);
    }

    public function test_エンドポイントは正しいシークレットで取込する(): void
    {
        $tenant = Tenant::factory()->create();
        config([
            'services.inbound.secret'    => 'test-secret-xyz',
            'services.inbound.tenant_id' => $tenant->id,
        ]);

        $sesReceivedAt = now()->subSeconds(1);
        $payload = [
            'ses_message_id' => 'msg-ok',
            'received_at'    => $sesReceivedAt->toIso8601String(),
            'raw_base64'     => base64_encode($this->buildRaw('<accept@enzian.example.com>', now()->subHours(2)->format('D, d M Y H:i:s O'))),
        ];

        $this->withHeader('X-Inbound-Secret', 'test-secret-xyz')
            ->postJson('/api/v1/inbound/email', $payload)
            ->assertStatus(200)
            ->assertJson(['stored' => true]);

        $row = Email::where('tenant_id', $tenant->id)
            ->where('rfc_message_id', 'accept@enzian.example.com')->first();
        $this->assertNotNull($row);
        $this->assertSame('ses-msg-ok', $row->gmail_message_id);
        $this->assertEqualsWithDelta($sesReceivedAt->timestamp, $row->arrived_at->timestamp, 5);
    }
}
