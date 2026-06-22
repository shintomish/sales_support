<?php

namespace Tests\Unit\Services;

use App\Models\Email;
use App\Services\ProjectMailScoringService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ProjectMailScoringService の抽出を検証する Unit テスト。
 *
 * 主目的: HTML 専用メール（body_text='' で取り込まれる整形メール）でも HTML 本文へ
 * フォールバックして営業担当・電話等を抽出できることの回帰防止
 * （2026-06-22 報告・メディアリンク案件 213262。body_text='' で ?? が効かず空抽出）。
 */
class ProjectMailScoringExtractionTest extends TestCase
{
    private function extract(Email $email): array
    {
        $svc = new ProjectMailScoringService();
        $m = new ReflectionMethod($svc, 'extract');
        $m->setAccessible(true);

        return $m->invoke($svc, $email);
    }

    public function test_falls_back_to_html_when_body_text_empty(): void
    {
        $html = '<!DOCTYPE html><html><head><style>p{color:#fff}.x{display:none}</style></head>'
            . '<body><p>○○様&nbsp;いつもお世話になっております。メディアリンクの佐光です。</p>'
            . '<p>下記、案件情報をお送り致します。</p>'
            . '<p>TEL：03-3455-2700</p></body></html>';

        $email = new Email();
        $email->subject = '【ML案件情報！】市ヶ谷リモート併用/Python・Linux';
        $email->body_text = '';            // HTML 専用メール
        $email->body_html = $html;
        $email->from_name = 'メディアリンク株式会社 営業部';
        $email->from_address = 'si_sales@medialink-ml.co.jp';

        $r = $this->extract($email);

        $this->assertSame('佐光', $r['sales_contact'], 'CSS を除いた HTML 本文から営業担当を抽出');
        $this->assertSame('03-3455-2700', $r['phone']);
    }
}
