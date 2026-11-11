<?php

namespace Tests\Feature\Services;

use App\Models\Email;
use App\Models\ProjectMailSource;
use App\Services\ProjectMailScoringService;
use Tests\TestCase;

/**
 * ドメインボーナス/ペナルティが「自己強化ループにならない」ことを守る回帰ガード（docs/740 G5）。
 *
 * 経緯: 以前はドメイン単位の案件率でスコアを ±20 補正していたが、rate が score 由来の
 * status に依存し、score に bonus 自身が含まれる自己強化ループになっていた
 * （特に -20 がドメインを恒久的に沈める）。中立性原則（content スコアのみ）に反するため
 * 2026-06-23 に domainBonus() は廃止され、常に bonus=0 を返す。
 *
 * 本テストは「どんな status 分布・score 値でも bonus は 0（集計もしない）」を固定し、
 * 将来うっかりドメイン補正を復活させる変更を fail させる。
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

    private function assertNoBonus(string $domain): void
    {
        $r = $this->service->domainBonus("sales@{$domain}", $this->authUser->tenant_id);

        $this->assertSame(0, $r['bonus'], "{$domain}: domainBonus が 0 以外を返した（廃止済のドメイン補正が復活している）");
        $this->assertSame(0, $r['sample'], "{$domain}: ドメイン集計が走っている（廃止済の集計が復活している）");
    }

    public function test_zero_project_rate_domain_gets_no_penalty(): void
    {
        // 旧仕様で -20 になっていた「案件率 0%（全 excluded）」のドメインでも、ペナルティは付かない。
        $this->seedDomain('low-quality-bp.example.co.jp', excluded: 50, included: 0);

        $this->assertNoBonus('low-quality-bp.example.co.jp');
    }

    public function test_bonus_is_zero_for_any_project_rate(): void
    {
        // 旧仕様の境界（<=20% → -20 / >=80% → +20）を含め、どの案件率でも 0。
        $cases = [
            // domain,              excluded, included
            ['rate-000.example.jp', 10, 0],
            ['rate-020.example.jp',  8, 2],
            ['rate-050.example.jp',  5, 5],
            ['rate-080.example.jp',  2, 8],
            ['rate-100.example.jp',  0, 10],
        ];

        foreach ($cases as [$domain, $excluded, $included]) {
            $this->seedDomain($domain, $excluded, $included);
            $this->assertNoBonus($domain);
        }
    }

    public function test_bonus_does_not_depend_on_score_values(): void
    {
        // score 値が大きく違っても結果は同じ（= score がドメイン補正に混入しない）。
        $this->seedDomain('score-low.example.jp',  excluded: 10, included: 0, score: 5);
        $this->seedDomain('score-high.example.jp', excluded: 10, included: 0, score: 95);

        $this->assertNoBonus('score-low.example.jp');
        $this->assertNoBonus('score-high.example.jp');
    }

    public function test_small_sample_domain_gets_no_bonus(): void
    {
        $this->seedDomain('tiny-sample.example.jp', excluded: 4, included: 0);

        $this->assertNoBonus('tiny-sample.example.jp');
    }
}
