<?php

namespace Tests\Feature\Services;

use App\Models\Email;
use App\Models\Tenant;
use App\Services\DailyReportBuilder;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * 日次レポートの「お問い合わせフォーム投稿」セクション（contact_forms）を検証する Feature テスト。
 * フォーム投稿(other + 本文 [ 御社名 ])のみ拾い、プレーン other / engineer は拾わないこと、
 * 0 件時は品質ゲートでセクションが落ちることを確認する。
 */
class DailyReportContactFormSectionTest extends TestCase
{
    private const FORM_BODY = "本メールは…\n\n"
        . "[ 御社名 ] Will Spark合同会社\n"
        . "[ 部署名 ] \n"
        . "[ ご担当者様 ] 村瀬 雄哉 (ムラセ ユウヤ)\n"
        . "[ メールアドレス ] y-murase@willspark.jp\n"
        . "[ お電話番号 ] 05071098139\n"
        . "[ ご住所 ]\n郵便番号: 5560011\n都道府県: 大阪府\n市区町村: 大阪市浪速区\n"
        . "町名: 難波中\n番地等: 1丁目16番11号\n建物名: \n"
        . "[ お問い合わせ項目 ] 【ご紹介】Java／Python／AWSエンジニア\n"
        . "[ お問い合わせ内容 ] 突然のご連絡失礼いたします。\n";

    private function makeEmail(int $tenantId, string $category, ?string $body): Email
    {
        return Email::factory()->create([
            'tenant_id'   => $tenantId,
            'category'    => $category,
            'body_text'   => $body,
            'received_at' => Carbon::yesterday('Asia/Tokyo')->addHours(12),
        ]);
    }

    public function test_contact_form_section_lists_only_form_submissions(): void
    {
        $tenant = Tenant::factory()->create();

        $form  = $this->makeEmail($tenant->id, 'other', self::FORM_BODY);
        $this->makeEmail($tenant->id, 'other', 'ご挨拶もかねてご連絡いたしました。'); // プレーン other（角括弧なし）
        $this->makeEmail($tenant->id, 'engineer', '[ 御社名 ] ダミー'); // engineer は対象外

        $report = app(DailyReportBuilder::class)->build($tenant->id);

        $this->assertArrayHasKey('contact_forms', $report['sections']);
        $section = $report['sections']['contact_forms'];
        $this->assertSame(1, $section['count'], 'フォーム投稿のみ1件');

        $item = $section['list'][0];
        $this->assertSame($form->id, $item['email_id']);
        $this->assertSame('Will Spark合同会社', $item['company']);
        $this->assertSame('村瀬 雄哉', $item['contact_person']);
        $this->assertSame('y-murase@willspark.jp', $item['email']);
        $this->assertStringStartsWith('〒556-0011 ', $item['address']);
        $this->assertSame('【ご紹介】Java／Python／AWSエンジニア', $item['inquiry_subject']);
    }

    public function test_contact_form_section_excluded_when_none(): void
    {
        $tenant = Tenant::factory()->create();
        $this->makeEmail($tenant->id, 'engineer', 'スキルシート添付'); // フォーム投稿なし

        $report = app(DailyReportBuilder::class)->build($tenant->id);

        $this->assertArrayNotHasKey('contact_forms', $report['sections'], '0件なら品質ゲートで非表示');
        $this->assertSame(0, $report['action_total'], 'フォーム投稿は action_total に加算しない');
    }
}
