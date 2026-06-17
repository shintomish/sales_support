<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use App\Models\Tenant;
use App\Services\KagoyaMailService;
use ReflectionMethod;
use Tests\Pgsql\PgsqlTestCase;

/**
 * バウンス silent-drop でも dedup anchor stub を必ず残すことを守る回帰ガード（docs/740 G6）。
 *
 * バウンス通知は本処理(分類/採点)から外すが、anchor が無いと 15分毎の取込で「新着」として
 * 再処理され続けるループになる。そのため category='bounce', is_read=true, body=null の最小 stub を
 * 必ず 1 行 insert し、rfc_message_id dedup の anchor とする (CLAUDE memory: bounce_stub_dedup_anchor)。
 *
 * private storeRawMessage を ReflectionMethod 経由で呼ぶ（既存 Dedup テストと同じパターン）。
 */
class KagoyaStoreRawMessageBounceStubTest extends PgsqlTestCase
{
    private function store(KagoyaMailService $svc, string $raw, int $tenantId, string $uid): bool
    {
        $method = new ReflectionMethod(KagoyaMailService::class, 'storeRawMessage');
        $method->setAccessible(true);
        return $method->invoke($svc, $raw, $tenantId, $uid, null);
    }

    private function buildBounceRaw(string $messageId, string $from = 'MAILER-DAEMON <mailer-daemon@kagoya.example.com>', string $subject = 'Undelivered Mail Returned to Sender'): string
    {
        return implode("\r\n", [
            "From: {$from}",
            'To: <outsource@aizen-sol.co.jp>',
            "Subject: {$subject}",
            'Date: ' . now()->format('D, d M Y H:i:s O'),
            "Message-ID: {$messageId}",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 7bit',
            '',
            'This is the mail system at host kagoya. Delivery to the following recipient failed.',
            '',
        ]);
    }

    public function test_バウンス取込でcategory_bounceかつis_read_trueのstubが1行残る(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(KagoyaMailService::class);
        $mid = '<bounce-stub-1@kagoya.example.com>';

        $result = $this->store($svc, $this->buildBounceRaw($mid), $tenant->id, 'imap-12345');

        // silent-drop（本処理に進まない）を示す false
        $this->assertFalse($result, 'バウンスは silent-drop で false を返す');

        $row = Email::where('tenant_id', $tenant->id)
            ->where('rfc_message_id', 'bounce-stub-1@kagoya.example.com')
            ->first();

        $this->assertNotNull($row, 'anchor stub が insert されていない（再処理ループの危険）');
        $this->assertSame('bounce', $row->category, 'category は bounce（classifyPending に拾わせない）');
        $this->assertTrue((bool) $row->is_read, 'is_read は true（未読カウントを汚さない）');
        $this->assertNull($row->body_text, 'stub の body_text は null');
        $this->assertNull($row->body_html, 'stub の body_html は null');
        $this->assertSame('imap-12345', $row->gmail_message_id, 'UID anchor が記録されている');
    }

    public function test_subject_ベースのバウンスでもstubが残る(): void
    {
        // From は通常でも Subject がバウンス文言なら stub 化される。
        $tenant = Tenant::factory()->create();
        $svc = app(KagoyaMailService::class);
        $mid = '<bounce-stub-subj@upstream.example.com>';

        $raw = $this->buildBounceRaw($mid, from: 'filter <filter@upstream.example.com>', subject: 'failure notice');
        $result = $this->store($svc, $raw, $tenant->id, 'imap-22222');

        $this->assertFalse($result);
        $row = Email::where('tenant_id', $tenant->id)->where('rfc_message_id', 'bounce-stub-subj@upstream.example.com')->first();
        $this->assertNotNull($row);
        $this->assertSame('bounce', $row->category);
    }

    public function test_同一バウンスの再配送でもstubは1行で_uid_anchorが前進する(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(KagoyaMailService::class);
        $mid = '<bounce-stub-dup@kagoya.example.com>';

        $first  = $this->store($svc, $this->buildBounceRaw($mid), $tenant->id, 'imap-100');
        $second = $this->store($svc, $this->buildBounceRaw($mid), $tenant->id, 'imap-205');

        $this->assertFalse($first, '初回バウンスは stub 化(false)');
        $this->assertFalse($second, '再配送も dedup で false');

        $rows = Email::where('tenant_id', $tenant->id)
            ->where('rfc_message_id', 'bounce-stub-dup@kagoya.example.com')->get();
        $this->assertCount(1, $rows, 'stub は 1 行だけ（二重 insert しない）');
        $this->assertSame('imap-205', $rows->first()->gmail_message_id, 'UID anchor が新しい方へ前進（再フェッチループ防止）');
    }

    public function test_正常メールはstubにならず本文を保持する(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(KagoyaMailService::class);
        $normalRaw = implode("\r\n", [
            'From: ENZIAN営業部 <eigyo@enzian.example.com>',
            'To: <outsource@aizen-sol.co.jp>',
            'Subject: テスト案件のご紹介',
            'Date: ' . now()->format('D, d M Y H:i:s O'),
            'Message-ID: <normal-1@enzian.example.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 7bit',
            '',
            'Java/Spring Boot の案件です。',
            '',
        ]);

        $result = $this->store($svc, $normalRaw, $tenant->id, 'imap-normal-1');

        $this->assertTrue($result, '正常メールは新規保存(true)');
        $row = Email::where('tenant_id', $tenant->id)->where('rfc_message_id', 'normal-1@enzian.example.com')->first();
        $this->assertNotNull($row);
        $this->assertNotSame('bounce', $row->category, '正常メールは bounce 扱いされない');
        $this->assertNotNull($row->body_text, '正常メールは本文を保持する');
    }
}
