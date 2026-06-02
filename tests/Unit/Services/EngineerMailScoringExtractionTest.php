<?php

namespace Tests\Unit\Services;

use App\Models\Email;
use App\Services\EngineerMailScoringService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * EngineerMailScoringService の正規表現抽出を検証する Unit テスト。
 *
 * 主目的: 全角スペース入りブラケットラベル（【最　寄】：xxx 等。Ksync 等の個人事業主紹介テンプレ）の
 * 抽出回帰を防ぐ。対策前は nearest_station が "寄】：川崎大師駅" と崩れ、unit_price/available_from/age が
 * null になっていた（2026-06-02 報告・本番953件）。
 */
class EngineerMailScoringExtractionTest extends TestCase
{
    private function extract(string $subject, string $body): array
    {
        $svc = new EngineerMailScoringService();
        $m = new ReflectionMethod($svc, 'extract');
        $m->setAccessible(true);

        $email = new Email();
        $email->subject = $subject;
        $email->body_text = $body;
        $email->setRelation('attachments', new Collection());

        return $m->invoke($svc, $email, false);
    }

    public function test_extracts_bracket_labels_with_fullwidth_space(): void
    {
        $body = "株式会社アイゼン・ソリューション\nご担当者 様\n\n"
            . "現在営業中の下記人材のご紹介をさせていただきます。\n\n"
            . "【名　前】：KS 男性 38歳  弊社個人事業主\n"
            . "【単　価】：自動車領域160万円前後\nそれ以外の領域120万円以上\n"
            . "【スキル】：C C++\n"
            . "【最　寄】：川崎大師駅（神奈川県）\n"
            . "【通　勤】：案件内容で出社も柔軟に検討可能\n"
            . "【稼　働】：7月\n";

        $r = $this->extract('【注力個人 7月〜】車載組込み×SDV', $body);

        $this->assertSame('川崎大師駅', $r['nearest_station'], 'ラベル記号を巻き込まず駅名のみ');
        $this->assertSame(38, $r['age']);
        $this->assertSame(160, $r['unit_price_min']);
        $this->assertSame('7月', $r['available_from']);
        $this->assertSame('個人事業主', $r['affiliation_type']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stationProvider')]
    public function test_extracts_clean_nearest_station(string $body, string $expected): void
    {
        $svc = new EngineerMailScoringService();
        $m = new ReflectionMethod($svc, 'extractNearestStation');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke($svc, $body));
    }

    public static function stationProvider(): array
    {
        return [
            'bracket label 駅 included'    => ["【最寄駅】舎人ライナー　江北駅\n", '舎人ライナー　江北駅'],
            'bracket label short'          => ["【最寄】北久里浜駅\n", '北久里浜駅'],
            'bracket label with colon'     => ["【最寄駅】：三鷹駅\n", '三鷹駅'],
            'fullwidth space label'        => ["【最　寄】：川崎大師駅（神奈川県）\n", '川崎大師駅'],
            'company prefix no space'      => ["最寄駅：JR新宿駅\n", '新宿駅'],
            'line name with 線'            => ["最寄駅：JR山手線渋谷駅\n", '渋谷駅'],
            'trailing affiliation junk'    => ["最寄り：中野駅・所属：弊社フリーランス\n", '中野駅'],
        ];
    }

    public function test_age_does_not_false_match_retirement_age_in_comment(): void
    {
        // 本文コメントの「60歳で定年退職」を現在年齢(64)と取り違えないこと。
        $body = "【名　前】：WY 男性 64歳  弊社個人事業主\n"
            . "【最　寄】：大和高田（奈良）\n"
            . "【稼　働】：即日\n"
            . "【コメント】：2021年3月に60歳で定年退職後、フリーランスとして活動しています。\n";

        $r = $this->extract('【★注力個人】エンジニア歴40年', $body);

        $this->assertSame(64, $r['age']);
        $this->assertSame('大和高田', $r['nearest_station']);
    }
}
