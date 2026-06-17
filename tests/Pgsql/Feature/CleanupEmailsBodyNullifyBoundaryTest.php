<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use Tests\Pgsql\PgsqlTestCase;

/**
 * emails:cleanup の本文NULL化 30日境界を守る回帰ガード（docs/740 G7）。
 *
 * CleanupEmails は分類済み(classified_at)が 30日超のメールの body_text/body_html を NULL化して
 * 容量を削減する (where classified_at < now()-30days)。閾値より新しいメールの本文は残す。
 * 本テストは閾値の向き(< 演算子)を境界値で固定する。
 *
 * Carbon の日時演算・Email モデル経由の UPDATE を含むため Pgsql テストとして実行する。
 * （knife-edge の「ちょうど30日前」はコマンド実行時刻との数ms差で揺れるため、
 *  29日=残る / 31日=NULL化 で閾値の向きを安定検証する）
 */
class CleanupEmailsBodyNullifyBoundaryTest extends PgsqlTestCase
{
    public function test_classified_body_is_nullified_only_beyond_30_days(): void
    {
        $this->actingAsUser();
        $tenantId = $this->authUser->tenant_id;

        // 29日前(閾値内=残る)
        $fresh = Email::factory()->create([
            'tenant_id'     => $tenantId,
            'subject'       => 'fresh',
            'body_text'     => '本文テキスト29',
            'body_html'     => '<p>html29</p>',
            'classified_at' => now()->subDays(29),
        ]);

        // 31日前(閾値超=NULL化)。Step2(90日削除)/Step3(未分類14日削除)には該当しない範囲。
        $stale = Email::factory()->create([
            'tenant_id'     => $tenantId,
            'subject'       => 'stale',
            'body_text'     => '本文テキスト31',
            'body_html'     => '<p>html31</p>',
            'classified_at' => now()->subDays(31),
        ]);

        $this->artisan('emails:cleanup')->assertExitCode(0);

        $fresh->refresh();
        $this->assertSame('本文テキスト29', $fresh->body_text, '29日前(閾値内)の本文が消えた');
        $this->assertSame('<p>html29</p>', $fresh->body_html);

        $stale->refresh();
        $this->assertNull($stale->body_text, '31日前(閾値超)の body_text が NULL化されていない');
        $this->assertNull($stale->body_html, '31日前(閾値超)の body_html が NULL化されていない');
    }

    public function test_unclassified_email_body_is_not_nullified(): void
    {
        $this->actingAsUser();

        // 未分類(classified_at=null)は本文NULL化(Step1)の対象外。
        // received_at は 14日以内にして未分類削除(Step3)も避ける。
        $email = Email::factory()->create([
            'tenant_id'     => $this->authUser->tenant_id,
            'subject'       => 'unclassified',
            'body_text'     => '未分類の本文',
            'body_html'     => '<p>unclassified</p>',
            'classified_at' => null,
            'received_at'   => now()->subDays(3),
        ]);

        $this->artisan('emails:cleanup')->assertExitCode(0);

        $email->refresh();
        $this->assertSame('未分類の本文', $email->body_text, '未分類メールの本文が消えた（NULL化対象外のはず）');
    }

    public function test_dry_run_does_not_nullify(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create([
            'tenant_id'     => $this->authUser->tenant_id,
            'body_text'     => 'dry本文',
            'body_html'     => '<p>dry</p>',
            'classified_at' => now()->subDays(31),
        ]);

        $this->artisan('emails:cleanup', ['--dry-run' => true])->assertExitCode(0);

        $email->refresh();
        $this->assertSame('dry本文', $email->body_text, 'dry-run なのに本文が NULL化された');
    }
}
