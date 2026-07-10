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

    /**
     * 【稼働開始日】即日 形式で available_from が "日】即日" と崩れる回帰を防ぐ
     * （2026-06-22 報告・EC-CUBE 技術者メール）。汎用パターンが「稼働開始」で
     * 部分マッチしてラベルの残り「日】…」を巻き込んでいた。
     */
    public function test_extracts_start_date_with_kaido_kaishi_bi_label(): void
    {
        $bracket = $this->extract('★人材情報★EC-CUBE', "【稼働開始日】即日\n【週稼働】5日\n");
        $this->assertSame('即日', $bracket['available_from']);

        $colon = $this->extract('要員', "稼働開始日：7月\nスキル：PHP\n");
        $this->assertSame('7月', $colon['available_from']);

        // ラベル内に稼働/参画/開始を含む各種ブラケットを汎用処理する
        $jiki = $this->extract('要員', "【稼働開始時期】：2026年7月〜\n");
        $this->assertSame('2026年7月〜', $jiki['available_from']);

        $sanka = $this->extract('要員', "【参画可能時期】2026年7月1日〜\n");
        $this->assertSame('2026年7月1日〜', $sanka['available_from']);

        // 勤務形態ラベルの誤マッチで「：／」等のゴミを拾わない
        $noise = $this->extract('要員', "稼働可能：／【フルリモート（完全在宅勤務）】案件希望\n");
        $this->assertNull($noise['available_from']);

        // 値が空のラベルは次行の別ラベル（【単価】等）を巻き込まない
        $emptyLabel = $this->extract('要員', "【稼働開始】\n【単価】50万\n【所属】弊社正社員\n");
        $this->assertNull($emptyLabel['available_from']);
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
            'line+etc slash prefix'        => ["【最寄り駅】JR湘南新宿線等/浦和駅\n", '浦和駅'],
            'multi station preserved'      => ["最寄駅：南草津駅/瀬田駅\n", '南草津駅/瀬田駅'],
            'line slash station kept'      => ["最寄駅：埼京線/板橋駅\n", '埼京線/板橋駅'],
            'line space station kept'      => ["最寄駅：田園都市線　宮崎台駅\n", '田園都市線　宮崎台駅'],
            'station starts with company'  => ["最寄り駅：京王堀之内駅（京王相模原線／新宿・渋谷）\n", '京王堀之内駅'],
            'company-name station kept'    => ["最寄駅：西武新宿駅\n", '西武新宿駅'],
        ];
    }

    /**
     * 希望単価の抽出。単金額（末尾「額」）・【単金】ブラケットは HTML 配信メールで多く、
     * 旧パターンがラベルを取りこぼしていた（2026-07-08。本番の no_unit_price → review 止まり）。
     * ラベル無しの単独「NN万」は誤検出（年商・利用者数等）を避けるため引き続き非対象。
     *
     * @param array{0:int|null,1:int|null} $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unitPriceProvider')]
    public function test_extracts_unit_price(string $text, array $expected): void
    {
        $svc = new EngineerMailScoringService();
        $m = new ReflectionMethod($svc, 'extractUnitPrice');
        $m->setAccessible(true);

        $this->assertSame($expected, $m->invoke($svc, $text));
    }

    public static function unitPriceProvider(): array
    {
        return [
            // 修正で拾えるようになったラベル付き（HTML配信メール由来）
            '単金額 単一'        => ['単金額：85万円（応相談）', [85, 85]],
            '単価額 単一'        => ['単価額：40万', [40, 40]],
            '単金額 範囲'        => ['単金額：60〜70万', [60, 70]],
            '【単金】単一'       => ['【単金】60万', [60, 60]],
            '【単 金】全角空白'  => ['【単 金】95万円 ※応相談', [95, 95]],
            // 既存パターンの非退行
            '単価コロン'         => ['単価：120万円/月', [120, 120]],
            '単金 範囲'          => ['単金 60〜70万', [60, 70]],
            '■単金額'           => ['■単金額：110万', [110, 110]],
            '【単価】前後'       => ['【単　価】：自動車領域160万円前後', [160, 160]],
            // 誤検出ガード（ラベル無しの NN万 は拾わない）
            '経験件数は非対象'   => ['経験10万件のプロジェクト', [null, null]],
            '年商は非対象'       => ['年商5000万円の企業', [null, null]],
            '利用者数は非対象'   => ['月200万人が利用', [null, null]],
        ];
    }

    public function test_extracts_non_dev_role_skills(): void
    {
        // 非開発ロール（ROLE_SKILLS）を検索用 skills に格納する（2026-07-10）。
        $r = $this->extract(
            'ヘルプデスク・運用保守の要員紹介',
            "ヘルプデスク／キッティング／社内SE(情シス)経験。監視業務も対応可。\n"
            . "コールセンター・電話対応・データ入力・一般事務・ITサポート・障害対応も可。Java も少々。\n即日稼働可能",
        );
        $skills = $r['skills'] ?? [];
        foreach ([
            'ヘルプデスク', '運用保守', 'キッティング', '社内SE', '情シス', '監視',
            'コールセンター', '電話対応', 'データ入力', '一般事務', 'ITサポート', '障害対応',
        ] as $role) {
            $this->assertContains($role, $skills, "{$role} が skills に入る");
        }
        $this->assertContains('Java', $skills, '既存の技術スタック抽出は維持');
    }

    public function test_extracts_dictionary_registered_surface(): void
    {
        // 辞書駆動: skill_aliases に登録された語（ROLE_SKILLS 定数に無い同義語）も抽出される。
        // 「サポートデスク」「カスタマーサポート」は seed migration で登録済み。
        $r = $this->extract('要員紹介', 'サポートデスク・カスタマーサポート経験。保守運用も対応。');
        $skills = $r['skills'] ?? [];
        $this->assertContains('サポートデスク', $skills, '辞書登録の同義語が抽出される');
        $this->assertContains('カスタマーサポート', $skills);
        $this->assertContains('保守運用', $skills);
    }

    public function test_role_skill_does_not_false_match_bare_word(): void
    {
        // 「運用」単体では「運用保守」「サーバー運用」を湧かせない（複合語のみ）。
        $r = $this->extract('要員紹介', "サーバーの運用を少し担当。Java 開発が中心。");
        $skills = $r['skills'] ?? [];
        $this->assertNotContains('運用保守', $skills);
        $this->assertNotContains('サーバー運用', $skills);
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
