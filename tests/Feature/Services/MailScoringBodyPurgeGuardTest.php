<?php

namespace Tests\Feature\Services;

use App\Models\Email;
use App\Models\Tenant;
use App\Services\EngineerMailScoringService;
use App\Services\ProjectMailScoringService;
use Tests\TestCase;

/**
 * 本文 purge ガードの回帰テスト。
 *
 * retention(30日 CleanupEmails)で body_text/body_html とも purge 済みのメールが、
 * catch-up 等で「初回スコア」されると、件名だけで誤った score/status が付き提案対象に
 * 混入する事故が起き得る（score() には本文空ガードが無く、rescoreAll のみ温存していた）。
 * score() は本文空なら calcScore を呼ばず excluded アンカー(reason='body_purged')を作る。
 */
class MailScoringBodyPurgeGuardTest extends TestCase
{
    private function makeEmail(string $subject, ?string $bodyText, ?string $bodyHtml = null): Email
    {
        $tenant = Tenant::factory()->create();

        return Email::factory()->create([
            'tenant_id'  => $tenant->id,
            'subject'    => $subject,
            'body_text'  => $bodyText,
            'body_html'  => $bodyHtml,
        ]);
    }

    // ── engineer ──────────────────────────────────────────────

    public function test_engineer_empty_body_is_excluded_as_body_purged(): void
    {
        // 件名は強い技術者シグナル（スキルシート=ENGINEER_A +15）だが本文は purge 済み（空）。
        // ガードが無ければ件名のみで採点されてしまう。
        $email = $this->makeEmail('技術者ご紹介 スキルシート 即日稼働可能', '', null);

        $ems = (new EngineerMailScoringService())->score($email);

        $this->assertSame('excluded', $ems->status, '件名のみでスコアせず excluded アンカー');
        $this->assertSame(0, (int) $ems->score);
        $this->assertContains('body_purged', $ems->score_reasons ?? []);
    }

    public function test_engineer_non_empty_body_is_not_marked_body_purged(): void
    {
        // 本文があれば通常採点され、body_purged ガードは発火しない。
        $email = $this->makeEmail(
            '技術者ご紹介 スキルシート',
            "スキルシートを添付いたします。\n単価：70万円\n最寄：新宿駅\nJava / Spring 経験10年\n即日稼働可能",
        );

        $ems = (new EngineerMailScoringService())->score($email);

        $this->assertNotContains('body_purged', $ems->score_reasons ?? [], '本文ありでガード非発火');
    }

    public function test_engineer_html_only_body_is_not_treated_as_empty(): void
    {
        // body_text は空でも body_html に内容があれば purge 扱いしない（calcScore と同じ本文式）。
        $email = $this->makeEmail(
            '技術者ご紹介',
            null,
            '<p>スキルシート添付。単価70万円。最寄：新宿駅。即日稼働可能。</p>',
        );

        $ems = (new EngineerMailScoringService())->score($email);

        $this->assertNotContains('body_purged', $ems->score_reasons ?? [], 'html 本文あり=purge 扱いしない');
    }

    // ── project ───────────────────────────────────────────────

    public function test_project_empty_body_is_excluded_as_body_purged(): void
    {
        $email = $this->makeEmail('【案件】Java 開発 即日 リモート可', '', null);

        $pms = (new ProjectMailScoringService())->score($email);

        $this->assertSame('excluded', $pms->status, '件名のみでスコアせず excluded アンカー');
        $this->assertSame(0, (int) $pms->score);
        $this->assertContains('body_purged', $pms->score_reasons ?? []);
    }

    public function test_project_non_empty_body_is_not_marked_body_purged(): void
    {
        $email = $this->makeEmail(
            '【案件】Java 開発',
            "下記案件をご紹介します。\n単価：80万円\n勤務地：東京（リモート可）\nJava / Spring\n開始：即日",
        );

        $pms = (new ProjectMailScoringService())->score($email);

        $this->assertNotContains('body_purged', $pms->score_reasons ?? [], '本文ありでガード非発火');
    }
}
