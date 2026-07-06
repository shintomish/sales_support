<?php

namespace Tests\Feature\Services;

use App\Models\Email;
use App\Models\ProjectMailSource;
use App\Models\Tenant;
use App\Services\EngineerMailScoringService;
use App\Services\ProjectMailScoringService;
use Tests\TestCase;

/**
 * HTML 専用配信メールの本文選択（resolveBody）回帰テスト。
 *
 * cuenote 等の配信ASPメールは body_text が「メールがうまく表示されない方はこちら」等の
 * 定型文だけ（非空だが極端に短い）で、実体は body_html にしかない。旧実装は
 * `body_text ?? strip_tags(html)` で body_text が非空なら HTML へ落ちず、件名＋定型文だけで
 * 採点していた。結果、単価(+15)以外が全滅し「単価はあるのに score 5点で除外」となる
 * 案件が本番で約1,900件発生していた（発生元 d-standing.co.jp 等）。
 *
 * resolveBody は body_text が薄い(<THIN_BODY_LEN)場合、htmlToText(body_html) の方が長ければ
 * そちらを採点・抽出に使う。
 */
class HtmlOnlyMailBodyScoringTest extends TestCase
{
    /** 本番 id=80352 を模した配信ASPメールの定型文本文（75文字相当・非空） */
    private const BOILERPLATE = 'メールがうまく表示されない方はこちらをご覧ください https://gy53.asp.example.jp/h/X8Jy9LIaY2EL1NTse4';

    private function makeEmail(string $subject, ?string $bodyText, ?string $bodyHtml): Email
    {
        $tenant = Tenant::factory()->create();

        return Email::factory()->create([
            'tenant_id'  => $tenant->id,
            'subject'    => $subject,
            'body_text'  => $bodyText,
            'body_html'  => $bodyHtml,
        ]);
    }

    public function test_project_thin_body_falls_back_to_html_and_is_not_excluded(): void
    {
        // 件名のみだと price_concrete(+15) と penalty_vague(-10) で 5点 → 除外だった。
        $email = $this->makeEmail(
            '【エンド直】65万円程度（スキル見合い）/ 基本リモート / SQL / データアナリスト',
            self::BOILERPLATE,
            '<html><head><style>body{width:100%}</style></head><body>'
            . '<p>案件のご紹介：大手企業のデータアナリスト</p>'
            . '<p>単価：65万円程度（スキル見合い）</p>'
            . '<p>勤務地：東京（基本リモート）</p>'
            . '<p>スキル：Python / SQL / AWS</p>'
            . '<p>工程：詳細設計〜開発</p><p>即日・長期</p></body></html>',
        );

        $pms = (new ProjectMailScoringService())->score($email);

        $this->assertNotSame('excluded', $pms->status, 'HTML 本文採用で除外圏を脱する');
        $this->assertGreaterThanOrEqual(40, (int) $pms->score, '5点ではなく HTML の内容で加点される');
        $reasons = $pms->score_reasons ?? [];
        $this->assertNotEmpty(
            array_filter($reasons, fn ($r) => str_starts_with($r, 'lang:') || str_starts_with($r, 'location:')),
            'HTML 本文の技術/勤務地キーワードが採点に反映される',
        );
    }

    public function test_project_rich_text_body_is_kept_and_not_replaced_by_html(): void
    {
        // body_text が十分（>=THIN_BODY_LEN）なら HTML には切り替えない（既存挙動の非退行）。
        $richText = "下記案件をご紹介します。\n単価：80万円\n勤務地：大阪\nJava / Spring / Oracle での開発\n"
            . "工程：基本設計から参画いただきます。\n開始：即日・長期案件です。\n"
            . "ご検討のほどよろしくお願いいたします。何卒よろしくお願い申し上げます。";
        $email = $this->makeEmail(
            '【案件】Java 開発',
            $richText,
            '<p>全く別の広告文面：無関係なキャンペーン情報</p>',
        );

        $pms = (new ProjectMailScoringService())->score($email);

        $this->assertNotSame('excluded', $pms->status);
        $this->assertContains('location:大阪', $pms->score_reasons ?? [], 'body_text 側の内容で採点される');
    }

    public function test_project_rescore_all_applies_html_fallback(): void
    {
        // rescoreAll() は score() を呼ばず採点ロジックをインライン複製しているため、
        // resolveBody への切替が漏れると「再スコアしても低スコアのまま」になる。
        // 本番既存行の是正は rescoreAll 経由なので、ここを回帰で守る。
        $tenant = Tenant::factory()->create();
        $email  = Email::factory()->create([
            'tenant_id' => $tenant->id,
            'subject'   => '【エンド直】65万円程度（スキル見合い）/ 基本リモート / SQL',
            'body_text' => self::BOILERPLATE,
            'body_html' => '<html><head><style>body{width:100%}</style></head><body>'
                . '<p>案件のご紹介：データアナリスト</p><p>単価：65万円程度</p>'
                . '<p>勤務地：東京（基本リモート）</p><p>スキル：Python / SQL / AWS</p>'
                . '<p>工程：詳細設計〜開発</p><p>即日・長期</p></body></html>',
        ]);
        // 旧ロジックで採点された「単価はあるのに 5点で除外」の既存行を模す。
        $pms = ProjectMailSource::factory()->create([
            'tenant_id'     => $tenant->id,
            'email_id'      => $email->id,
            'score'         => 5,
            'status'        => 'excluded',
            'score_reasons' => ['price_concrete', 'penalty_vague:スキル見合い'],
        ]);

        (new ProjectMailScoringService())->rescoreAll(null, 0, $tenant->id);

        $pms->refresh();
        $this->assertGreaterThanOrEqual(40, (int) $pms->score, 'rescoreAll が HTML 本文を採用して加点し直す');
        $this->assertNotSame('excluded', $pms->status, '除外圏を脱する');
    }

    public function test_engineer_thin_body_falls_back_to_html(): void
    {
        $email = $this->makeEmail(
            '技術者ご紹介',
            self::BOILERPLATE,
            '<html><head><style>a{color:red}</style></head><body>'
            . '<p>弊社所属のエンジニアをご紹介します。フリーランスも対応可能。</p>'
            . '<p>スキル：Java / Spring / AWS / React</p>'
            . '<p>即日稼働可能・稼働率100%</p></body></html>',
        );

        $ems = (new EngineerMailScoringService())->score($email);

        $reasons = $ems->score_reasons ?? [];
        $this->assertNotEmpty(
            array_filter($reasons, fn ($r) => str_starts_with($r, 'tech:') || str_starts_with($r, 'affiliation:')),
            'HTML 本文の技術/所属キーワードが採点に反映される',
        );
    }
}
