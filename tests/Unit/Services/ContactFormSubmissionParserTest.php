<?php

namespace Tests\Unit\Services;

use App\Services\ContactFormSubmissionParser;
use PHPUnit\Framework\TestCase;

/**
 * SmoothContact フォーム投稿本文のヘッダ項目抽出を検証する Unit テスト（DB 非依存）。
 * 本番実データ 3 パターンを使用（住所フル / 全空 / 一部・番地等に住所再掲）。
 */
class ContactFormSubmissionParserTest extends TestCase
{
    private ContactFormSubmissionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ContactFormSubmissionParser();
    }

    public function test_parses_full_address_submission(): void
    {
        $body = "本メールは、フォーム投稿時の受信通知を「有効」にされているユーザー様へお送りしております。\n\n"
            . "[ 御社名 ] Will Spark合同会社\n"
            . "[ 部署名 ] \n"
            . "[ ご担当者様 ] 村瀬 雄哉 (ムラセ ユウヤ)\n"
            . "[ メールアドレス ] y-murase@willspark.jp\n"
            . "[ お電話番号 ] 05071098139\n"
            . "[ ご住所 ]\n"
            . "郵便番号: 5560011\n"
            . "都道府県: 大阪府\n"
            . "市区町村: 大阪市浪速区\n"
            . "町名: 難波中\n"
            . "番地等: 1丁目16番11号\n"
            . "建物名: レック難波ビル203号室\n"
            . "[ お問い合わせ項目 ] 【ご紹介】フルリモート対応・要件定義〜開発まで一気通貫のJava／Python／AWSエンジニア\n"
            . "[ お問い合わせ内容 ] 突然のご連絡失礼いたします。\n";

        $r = $this->parser->parse($body);

        $this->assertSame('Will Spark合同会社', $r['company']);
        $this->assertNull($r['department'], '部署名は空なので null');
        $this->assertSame('村瀬 雄哉', $r['contact_person'], '読み仮名を除去');
        $this->assertSame('y-murase@willspark.jp', $r['email']);
        $this->assertSame('05071098139', $r['phone']);
        $this->assertSame('〒556-0011 大阪府大阪市浪速区難波中1丁目16番11号レック難波ビル203号室', $r['address']);
        $this->assertSame('【ご紹介】フルリモート対応・要件定義〜開発まで一気通貫のJava／Python／AWSエンジニア', $r['inquiry_subject']);
    }

    public function test_parses_submission_with_empty_address(): void
    {
        $body = "[ 御社名 ] 株式会社フレッシー\n"
            . "[ 部署名 ] \n"
            . "[ ご担当者様 ] 藤田 舞子 (フジタ マイコ)\n"
            . "[ メールアドレス ] sales2-1@fresshi.jp\n"
            . "[ お電話番号 ] 080-3589-2385\n"
            . "[ ご住所 ]\n"
            . "郵便番号: \n都道府県: \n市区町村: \n町名: \n番地等: \n建物名: \n"
            . "[ お問い合わせ項目 ] 情報交換\n"
            . "[ お問い合わせ内容 ] ご担当者様\n";

        $r = $this->parser->parse($body);

        $this->assertSame('株式会社フレッシー', $r['company']);
        $this->assertNull($r['department']);
        $this->assertSame('藤田 舞子', $r['contact_person']);
        $this->assertSame('sales2-1@fresshi.jp', $r['email']);
        $this->assertSame('080-3589-2385', $r['phone']);
        $this->assertNull($r['address'], '住所サブフィールドが全て空なら null');
        $this->assertSame('情報交換', $r['inquiry_subject']);
    }

    public function test_parses_submission_with_partial_address(): void
    {
        $body = "[ 御社名 ] 株式会社FORMUS（フォーマス）\n"
            . "[ 部署名 ] 採用支援部\n"
            . "[ ご担当者様 ] 伊藤 桜良 (イトウ サクラ)\n"
            . "[ メールアドレス ] s.ito@formus.jp\n"
            . "[ お電話番号 ] 0368202161\n"
            . "[ ご住所 ]\n"
            . "郵便番号: 1010041\n"
            . "都道府県: 東京都\n"
            . "市区町村: 千代田区\n"
            . "町名: 神田須田町\n"
            . "番地等: 千代田区神田須田町1-7-8 VORT秋葉原IV 2F\n"
            . "建物名: \n"
            . "[ お問い合わせ項目 ] 【エンジニア経験者採用／月平均4名採用の取り組み】株式会社FORMUS/伊藤\n"
            . "[ お問い合わせ内容 ] 採用ご担当者様\n";

        $r = $this->parser->parse($body);

        $this->assertSame('株式会社FORMUS（フォーマス）', $r['company']);
        $this->assertSame('採用支援部', $r['department']);
        $this->assertSame('伊藤 桜良', $r['contact_person']);
        $this->assertSame('s.ito@formus.jp', $r['email']);
        $this->assertStringStartsWith('〒101-0041 ', $r['address']);
        $this->assertStringContainsString('東京都', $r['address']);
        $this->assertSame('【エンジニア経験者採用／月平均4名採用の取り組み】株式会社FORMUS/伊藤', $r['inquiry_subject']);
    }

    public function test_returns_nulls_for_non_form_body(): void
    {
        $r = $this->parser->parse("いつもお世話になっております。ご挨拶もかねてご連絡いたしました。");

        $this->assertNull($r['company']);
        $this->assertNull($r['contact_person']);
        $this->assertNull($r['email']);
        $this->assertNull($r['address']);
        $this->assertNull($r['inquiry_subject']);
    }
}
