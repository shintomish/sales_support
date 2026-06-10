<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\Tenant;
use App\Services\KagoyaMailService;
use App\Services\SupabaseStorageService;
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

    private function buildRawWithTwoAttachments(): string
    {
        $b64 = fn(string $s) => chunk_split(base64_encode($s), 76, "\r\n");
        return implode("\r\n", [
            'From: ENZIAN営業部 <eigyo@enzian.example.com>',
            'To: <outsource@aizen-sol.co.jp>',
            'Subject: 添付テスト案件',
            'Date: ' . now()->subHours(2)->format('D, d M Y H:i:s O'),
            'Message-ID: <att-1@enzian.example.com>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="BOUND1"',
            '',
            '--BOUND1',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 7bit',
            '',
            '案件の詳細は添付をご確認ください。',
            '--BOUND1',
            // 同名 doc.pdf を 2 件 → part_index で衝突回避できることを検証する
            'Content-Type: application/pdf; name="doc.pdf"',
            'Content-Disposition: attachment; filename="doc.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            trim($b64('PDF-A-CONTENT')),
            '--BOUND1',
            'Content-Type: application/pdf; name="doc.pdf"',
            'Content-Disposition: attachment; filename="doc.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            trim($b64('PDF-B-CONTENT')),
            '--BOUND1--',
            '',
        ]);
    }

    public function test_添付は_part_index_ベースで一括保存され同名でも衝突しない(): void
    {
        $tenant = Tenant::factory()->create();
        config(['services.inbound.tenant_id' => $tenant->id]);

        // Storage はモック化（ネットワーク回避）。uploadBinary は受け取った path をそのまま返し、
        // storage_path に part_index 由来の path が入ることを検証できるようにする。
        $this->mock(SupabaseStorageService::class, function ($m) {
            $m->shouldReceive('uploadBinary')
                ->andReturnUsing(fn($binary, $path, $mime) => $path);
        });

        $stored = app(KagoyaMailService::class)->storeRawFromSes(
            $this->buildRawWithTwoAttachments(),
            'att-msg-id',
            now()->subSeconds(1)->toIso8601String(),
        );
        $this->assertTrue($stored);

        $email = Email::where('tenant_id', $tenant->id)
            ->where('rfc_message_id', 'att-1@enzian.example.com')->first();
        $this->assertNotNull($email);

        $atts = EmailAttachment::where('email_id', $email->id)->orderBy('part_index')->get();
        $this->assertCount(2, $atts);

        // part_index は MIME walk 順 (0,1)。同名 doc.pdf でも path が part_index で分かれる。
        $this->assertSame(0, (int) $atts[0]->part_index);
        $this->assertSame(1, (int) $atts[1]->part_index);
        $this->assertSame('doc.pdf', $atts[0]->filename);
        $this->assertSame('doc.pdf', $atts[1]->filename);
        $this->assertStringContainsString("/{$email->id}/0_doc.pdf", (string) $atts[0]->storage_path);
        $this->assertStringContainsString("/{$email->id}/1_doc.pdf", (string) $atts[1]->storage_path);
        $this->assertNotEquals($atts[0]->storage_path, $atts[1]->storage_path);

        // bulk insert でも created_at/updated_at が手動セットされている
        $this->assertNotNull($atts[0]->created_at);
        $this->assertNotNull($atts[0]->updated_at);
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
