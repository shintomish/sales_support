<?php

namespace Tests\Feature\Services;

use App\Models\Email;
use App\Models\ProjectMailSource;
use App\Services\ProjectMailScoringService;
use Tests\TestCase;

/**
 * ドメインボーナス/ペナルティが「自己強化ループにならない」ことを守る回帰ガード（docs/740 G5）。
 *
 * 背景: ドメイン単位の案件率でスコアを ±20 補正する。過去に「除外されるとさらに下がり
 * 永久除外される」自己強化ループの懸念が議論された (GFD 0%/1380 件)。
 *
 * 現状の domainBonus() は status 分布(案件率)のみの関数で {-20, 0, +20} に限定され、
 * 過去の score 値を入力に含まない。本テストはその「中立な入力 + 有界出力(floor)」を
 * 固定し、将来うっかり「score を入力に混ぜる」「ペナルティを累積させる」変更を fail させる。
 */
class DomainBonusNeutralityTest extends TestCase
{
    private ProjectMailScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
        $this->service = new ProjectMailScoringService();
    }

    /** 指定ドメインに excluded/その他 status の PMS を作る */
    private function seedDomain(string $domain, int $excluded, int $included, int $score = 50): void
    {
        for ($i = 0; $i < $excluded; $i++) {
            $this->makePms($domain, 'excluded', $score);
        }
        for ($i = 0; $i < $included; $i++) {
            $this->makePms($domain, 'new', $score);
        }
    }

    private function makePms(string $domain, string $status, int $score): void
    {
        $email = Email::factory()->create([
            'tenant_id'    => $this->authUser->tenant_id,
            'from_address' => "sales@{$domain}",
            'category'     => 'project',
        ]);
        ProjectMailSource::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'email_id'  => $email->id,
            'status'    => $status,
            'score'     => $score,
        ]);
    }

    public function test_penalty_floors_at_minus_20_and_does_not_diverge(): void
    {
        // 案件率 0%(全 excluded) のドメインを大量に積んでも、ペナルティは -20 が下限。
        // 累積発散(-40/-60...)しないこと = 自己強化ループ防止の核心。
        $this->seedDomain('low-quality-bp.example.co.jp', excluded: 50, included: 0);

        $r = $this->service->domainBonus('sales@low-quality-bp.example.co.jp', $this->authUser->tenant_id);

        $this->assertSame(-20, $r['bonus'], 'ペナルティが -20 を超えて発散している（自己強化ループの疑い）');
        $this->assertSame(50, $r['sample']);
        $this->assertEquals(0, $r['rate']);
    }

    public function test_bonus_is_bounded_to_known_set(): void
    {
        // rate に応じて出力は {-20, 0, +20} のいずれかに限定（有界）。
        $cases = [
            // domain,                excluded, included, expected_bonus
            ['rate-000.example.jp',   10, 0,  -20],  // 0%   → -20
            ['rate-020.example.jp',    8, 2,  -20],  // 20%  → -20 (<=0.2 境界)
            ['rate-050.example.jp',    5, 5,    0],  // 50%  → 0
            ['rate-080.example.jp',    2, 8,   20],  // 80%  → +20 (>=0.8 境界)
            ['rate-100.example.jp',    0, 10,  20],  // 100% → +20
        ];

        foreach ($cases as [$domain, $excluded, $included, $expected]) {
            $this->seedDomain($domain, $excluded, $included);
            $r = $this->service->domainBonus("sales@{$domain}", $this->authUser->tenant_id);
            $this->assertContains($r['bonus'], [-20, 0, 20], "{$domain}: bonus が想定集合外");
            $this->assertSame($expected, $r['bonus'], "{$domain}: rate に対する bonus が不正");
        }
    }

    public function test_bonus_depends_on_status_distribution_not_score_values(): void
    {
        // status 分布が同一(共に案件率0%=全 excluded)なら、score 値が大きく違っても bonus は同じ。
        // = ペナルティ入力に score を混ぜていないことの保証。
        $this->seedDomain('score-low.example.jp',  excluded: 10, included: 0, score: 5);
        $this->seedDomain('score-high.example.jp', excluded: 10, included: 0, score: 95);

        $low  = $this->service->domainBonus('sales@score-low.example.jp', $this->authUser->tenant_id);
        $high = $this->service->domainBonus('sales@score-high.example.jp', $this->authUser->tenant_id);

        $this->assertSame($low['bonus'], $high['bonus'], 'score 値の違いで bonus が変わる（中立性違反・score がペナルティに混入）');
        $this->assertSame(-20, $low['bonus']);
    }

    public function test_below_min_sample_yields_no_bonus(): void
    {
        // 最低サンプル(5件)未満なら、案件率に関わらず bonus 0（過小データで決めつけない）。
        $this->seedDomain('tiny-sample.example.jp', excluded: 4, included: 0);

        $r = $this->service->domainBonus('sales@tiny-sample.example.jp', $this->authUser->tenant_id);

        $this->assertSame(0, $r['bonus']);
        $this->assertSame(4, $r['sample']);
    }
}
