<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use App\Models\Tenant;
use App\Services\KagoyaMailService;
use ReflectionMethod;
use Tests\Pgsql\PgsqlTestCase;

/**
 * KagoyaMailService::storeRawMessage の rfc_message_id 重複排除テスト。
 *
 * Kagoya 二重配送（同一 Message-ID が別 UID で再到着）や Phase 1 の SES 受信経路と
 * IMAP バックアップの二重登録を防ぐ。dedup 時に IMAP の UID ウォーターマークを進める
 * anchor-advance（再フェッチループ防止）も pin する。
 *
 * private な storeRawMessage を ReflectionMethod 経由で呼ぶ。
 */
class KagoyaStoreRawMessageDedupTest extends PgsqlTestCase
{
    private function store(KagoyaMailService $svc, string $raw, int $tenantId, string $uid): bool
    {
        $method = new ReflectionMethod(KagoyaMailService::class, 'storeRawMessage');
        $method->setAccessible(true);
        return $method->invoke($svc, $raw, $tenantId, $uid, null);
    }

    private function buildRaw(string $messageId, string $subject = 'テスト案件のご紹介'): string
    {
        return implode("\r\n", [
            'From: ENZIAN営業部 <eigyo@enzian.example.com>',
            'To: <outsource@aizen-sol.co.jp>',
            "Subject: {$subject}",
            'Date: ' . now()->format('D, d M Y H:i:s O'),
            "Message-ID: {$messageId}",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 7bit',
            '',
            'Java/Spring Boot の案件です。',
            '',
        ]);
    }

    public function test_同一_message_id_の再配送は二重登録されない(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(KagoyaMailService::class);
        $mid = '<dup-12345@enzian.example.com>';

        $first  = $this->store($svc, $this->buildRaw($mid), $tenant->id, 'imap-100');
        $second = $this->store($svc, $this->buildRaw($mid), $tenant->id, 'imap-105');

        $this->assertTrue($first, '初回は新規保存される');
        $this->assertFalse($second, '同一 Message-ID の2通目は保存されない（dedup）');

        $rows = Email::where('tenant_id', $tenant->id)
            ->where('rfc_message_id', 'dup-12345@enzian.example.com')->get();
        $this->assertCount(1, $rows, '行は1件だけ');
    }

    public function test_imap_重複時に_uid_anchor_が前進する(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(KagoyaMailService::class);
        $mid = '<advance-1@enzian.example.com>';

        // imap-100 で保存 → 同じ Message-ID が UID 105 で再到着
        $this->store($svc, $this->buildRaw($mid), $tenant->id, 'imap-100');
        $this->store($svc, $this->buildRaw($mid), $tenant->id, 'imap-105');

        $row = Email::where('tenant_id', $tenant->id)
            ->where('rfc_message_id', 'advance-1@enzian.example.com')->first();
        $this->assertSame('imap-105', $row->gmail_message_id,
            'より新しい UID に anchor が前進し、lastUid 導出が止まらない');
    }

    public function test_より古い_uid_では_anchor_を後退させない(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(KagoyaMailService::class);
        $mid = '<no-regress@enzian.example.com>';

        $this->store($svc, $this->buildRaw($mid), $tenant->id, 'imap-200');
        $this->store($svc, $this->buildRaw($mid), $tenant->id, 'imap-100'); // 古い UID

        $row = Email::where('tenant_id', $tenant->id)
            ->where('rfc_message_id', 'no-regress@enzian.example.com')->first();
        $this->assertSame('imap-200', $row->gmail_message_id,
            'より古い UID では anchor を後退させない');
    }

    public function test_ses_経路の重複は既存_imap_anchor_を奪わない(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(KagoyaMailService::class);
        $mid = '<ses-vs-imap@enzian.example.com>';

        // 先に IMAP で保存済み → 後から SES 経路（ses-xxx）で同一メール
        $this->store($svc, $this->buildRaw($mid), $tenant->id, 'imap-300');
        $second = $this->store($svc, $this->buildRaw($mid), $tenant->id, 'ses-abcdef');

        $this->assertFalse($second, 'SES 経路の重複も保存しない');
        $row = Email::where('tenant_id', $tenant->id)
            ->where('rfc_message_id', 'ses-vs-imap@enzian.example.com')->first();
        $this->assertSame('imap-300', $row->gmail_message_id,
            'SES 重複は imap anchor を ses-* に書き換えない（IMAP ウォーターマーク維持）');
    }

    public function test_別テナントの同一_message_id_は別行として保存される(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $svc = app(KagoyaMailService::class);
        $mid = '<cross-tenant@enzian.example.com>';

        // gmail_message_id はグローバル UNIQUE のため UID は別値を使う。
        // dedup が tenant 単位であること（別テナントは別行になる）を pin する。
        $a = $this->store($svc, $this->buildRaw($mid), $tenantA->id, 'imap-901');
        $b = $this->store($svc, $this->buildRaw($mid), $tenantB->id, 'imap-902');

        $this->assertTrue($a);
        $this->assertTrue($b, 'dedup は tenant 単位（別テナントは別行）');
    }
}
