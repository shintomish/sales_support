<?php

namespace Tests\Unit\Services;

use App\Models\Engineer;
use App\Models\EngineerProfile;
use App\Models\EngineerSkill;
use App\Models\ProjectRequiredSkill;
use App\Models\PublicProject;
use App\Models\Skill;
use App\Services\MatchingService;
use Tests\TestCase;

/**
 * MatchingService のスコア計算ロジックを検証する Unit テスト。
 *
 * Claude API は呼び出さない（calculate のみ対象）。
 * テナント分離はテストの主目的ではないので、authUser を 1 つ作って共通テナントで
 * 案件・技術者を作成する。
 */
class MatchingServiceTest extends TestCase
{
    private MatchingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
        $this->service = new MatchingService();
    }

    // ─── スキルスコア ───

    public function test_skill_score_is_100_when_no_required_skills(): void
    {
        $project  = PublicProject::factory()->create(['unit_price_min' => 60, 'unit_price_max' => 80]);
        $engineer = $this->makeEngineer(['min' => 60, 'max' => 80]);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(100.0, (float) $result['skill_match_score']);
    }

    public function test_skill_score_full_match_required_skill_with_sufficient_experience(): void
    {
        $skill = Skill::create(['name' => 'PHP', 'category' => 'language']);

        $project = PublicProject::factory()->create();
        ProjectRequiredSkill::create([
            'project_id'           => $project->id,
            'skill_id'             => $skill->id,
            'is_required'          => true,
            'min_experience_years' => 3,
        ]);

        $engineer = $this->makeEngineer();
        EngineerSkill::create([
            'tenant_id'        => $engineer->tenant_id,
            'engineer_id'      => $engineer->id,
            'skill_id'         => $skill->id,
            'experience_years' => 5,
        ]);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(100.0, (float) $result['skill_match_score']);
    }

    public function test_skill_score_zero_when_no_matching_skill(): void
    {
        $skillA = Skill::create(['name' => 'Go',     'category' => 'language']);
        $skillB = Skill::create(['name' => 'Python', 'category' => 'language']);

        $project = PublicProject::factory()->create();
        ProjectRequiredSkill::create([
            'project_id'           => $project->id,
            'skill_id'             => $skillA->id,
            'is_required'          => true,
            'min_experience_years' => 3,
        ]);

        $engineer = $this->makeEngineer();
        EngineerSkill::create([
            'tenant_id'        => $engineer->tenant_id,
            'engineer_id'      => $engineer->id,
            'skill_id'         => $skillB->id,
            'experience_years' => 5,
        ]);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(0.0, (float) $result['skill_match_score']);
    }

    public function test_skill_score_partial_match_with_insufficient_experience(): void
    {
        $skill = Skill::create(['name' => 'Laravel', 'category' => 'framework']);

        $project = PublicProject::factory()->create();
        ProjectRequiredSkill::create([
            'project_id'           => $project->id,
            'skill_id'             => $skill->id,
            'is_required'          => true,
            'min_experience_years' => 4,
        ]);

        $engineer = $this->makeEngineer();
        EngineerSkill::create([
            'tenant_id'        => $engineer->tenant_id,
            'engineer_id'      => $engineer->id,
            'skill_id'         => $skill->id,
            'experience_years' => 2,
        ]);

        $result = $this->service->calculate($project, $engineer);

        // 2/4 = 0.5 → 50%
        $this->assertSame(50.0, (float) $result['skill_match_score']);
    }

    // ─── 価格スコア ───

    public function test_price_score_is_50_when_either_side_missing(): void
    {
        $project = PublicProject::factory()->create([
            'unit_price_min' => null,
            'unit_price_max' => null,
        ]);
        $engineer = $this->makeEngineer(['min' => 60, 'max' => 80]);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(50.0, (float) $result['price_match_score']);
    }

    public function test_price_score_zero_when_no_overlap(): void
    {
        // 案件 60-70万, 技術者 90-110万 → 重なりなし
        $project  = PublicProject::factory()->create(['unit_price_min' => 60, 'unit_price_max' => 70]);
        $engineer = $this->makeEngineer(['min' => 90, 'max' => 110]);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(0.0, (float) $result['price_match_score']);
    }

    public function test_price_score_high_when_full_overlap(): void
    {
        // 案件・技術者とも 60-80万 で完全一致
        $project  = PublicProject::factory()->create(['unit_price_min' => 60, 'unit_price_max' => 80]);
        $engineer = $this->makeEngineer(['min' => 60, 'max' => 80]);

        $result = $this->service->calculate($project, $engineer);

        // 重なり = 20, avgRange = 20 → 100%
        $this->assertSame(100.0, (float) $result['price_match_score']);
    }

    // ─── 勤務地スコア ───

    public function test_location_score_remote_remote_is_100(): void
    {
        $project  = PublicProject::factory()->create(['work_style' => 'remote']);
        $engineer = $this->makeEngineer(['work_style' => 'remote']);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(100.0, (float) $result['location_match_score']);
    }

    public function test_location_score_remote_one_side_is_90(): void
    {
        $project  = PublicProject::factory()->create(['work_style' => 'remote']);
        $engineer = $this->makeEngineer(['work_style' => 'office']);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(90.0, (float) $result['location_match_score']);
    }

    public function test_location_score_hybrid_hybrid_is_80(): void
    {
        $project  = PublicProject::factory()->create(['work_style' => 'hybrid']);
        $engineer = $this->makeEngineer(['work_style' => 'hybrid']);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(80.0, (float) $result['location_match_score']);
    }

    public function test_location_score_30_when_styles_differ_and_no_location_match(): void
    {
        $project = PublicProject::factory()->create([
            'work_style'    => 'office',
            'work_location' => '東京都港区',
        ]);
        $engineer = $this->makeEngineer([
            'work_style'         => 'office',
            'preferred_location' => '大阪府',
        ]);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(30.0, (float) $result['location_match_score']);
    }

    public function test_location_score_70_when_office_styles_share_location(): void
    {
        $project = PublicProject::factory()->create([
            'work_style'    => 'office',
            'work_location' => '東京都港区',
        ]);
        $engineer = $this->makeEngineer([
            'work_style'         => 'office',
            'preferred_location' => '東京都',
        ]);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(70.0, (float) $result['location_match_score']);
    }

    // ─── 稼働可能スコア ───

    public function test_availability_score_100_when_engineer_available_before_project_start(): void
    {
        $project = PublicProject::factory()->create(['start_date' => now()->addMonths(2)->format('Y-m-d')]);
        $engineer = $this->makeEngineer(['available_from' => now()->format('Y-m-d')]);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(100.0, (float) $result['availability_match_score']);
    }

    public function test_availability_score_80_when_within_one_month_late(): void
    {
        $project  = PublicProject::factory()->create(['start_date' => '2026-06-01']);
        $engineer = $this->makeEngineer(['available_from' => '2026-06-15']);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(80.0, (float) $result['availability_match_score']);
    }

    public function test_availability_score_60_when_within_two_months_late(): void
    {
        $project  = PublicProject::factory()->create(['start_date' => '2026-06-01']);
        $engineer = $this->makeEngineer(['available_from' => '2026-07-20']);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(60.0, (float) $result['availability_match_score']);
    }

    public function test_availability_score_50_when_either_date_missing(): void
    {
        $project  = PublicProject::factory()->create(['start_date' => null]);
        $engineer = $this->makeEngineer(['available_from' => null]);

        $result = $this->service->calculate($project, $engineer);

        $this->assertSame(50.0, (float) $result['availability_match_score']);
    }

    // ─── 総合スコア・キャッシュ ───

    public function test_total_score_uses_weighted_sum(): void
    {
        $project  = PublicProject::factory()->create([
            'unit_price_min' => 60, 'unit_price_max' => 80,
            'work_style'     => 'remote',
            'start_date'     => now()->addMonth()->format('Y-m-d'),
        ]);
        $engineer = $this->makeEngineer([
            'min' => 60, 'max' => 80,
            'work_style'     => 'remote',
            'available_from' => now()->format('Y-m-d'),
        ]);

        $result = $this->service->calculate($project, $engineer);

        // skill 100 (no required) * 0.50 + price 100 * 0.25 + location 100 * 0.15 + availability 100 * 0.10
        $this->assertSame(100.0, (float) $result['score']);
    }

    public function test_calculate_persists_matching_score_row(): void
    {
        $project  = PublicProject::factory()->create();
        $engineer = $this->makeEngineer();

        $this->service->calculate($project, $engineer);

        $this->assertDatabaseHas('matching_scores', [
            'project_id'  => $project->id,
            'engineer_id' => $engineer->id,
        ]);
    }

    public function test_calculate_updates_existing_score_row(): void
    {
        $project  = PublicProject::factory()->create();
        $engineer = $this->makeEngineer();

        $this->service->calculate($project, $engineer);
        $this->service->calculate($project, $engineer);

        $count = \App\Models\MatchingScore::where([
            'project_id'  => $project->id,
            'engineer_id' => $engineer->id,
        ])->count();
        $this->assertSame(1, $count);
    }

    // ─── helper ───

    /**
     * @param array{
     *   min?: float|null, max?: float|null,
     *   work_style?: string|null, preferred_location?: string|null,
     *   available_from?: string|null,
     * } $profile
     */
    private function makeEngineer(array $profile = []): Engineer
    {
        $engineer = Engineer::factory()->create();

        EngineerProfile::create([
            'tenant_id'              => $engineer->tenant_id,
            'engineer_id'            => $engineer->id,
            'is_public'              => true,
            'desired_unit_price_min' => $profile['min']                ?? null,
            'desired_unit_price_max' => $profile['max']                ?? null,
            'work_style'             => $profile['work_style']         ?? null,
            'preferred_location'     => $profile['preferred_location'] ?? null,
            'available_from'         => $profile['available_from']     ?? null,
        ]);

        return $engineer->fresh();
    }
}
