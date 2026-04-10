<?php

namespace Tests\Feature\Api;

use App\Models\Engineer;
use App\Models\EngineerProfile;
use App\Models\EngineerSkill;
use App\Models\PublicProject;
use App\Models\ProjectRequiredSkill;
use App\Models\Skill;
use App\Models\Tenant;
use App\Services\MatchingService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MatchingControllerTest extends TestCase
{
    // ───────────────────────────────────────────
    // recommendEngineers（案件→技術者）
    // ───────────────────────────────────────────

    public function test_recommend_engineers_returns_scored_list(): void
    {
        $this->actingAsUser();

        $project  = PublicProject::factory()->published()->create();
        $engineer = Engineer::factory()->create();
        EngineerProfile::create([
            'tenant_id'   => $engineer->tenant_id,
            'engineer_id' => $engineer->id,
            'is_public'   => true,
            'work_style'  => 'remote',
        ]);

        $response = $this->getJson("/api/v1/matching/projects/{$project->id}/engineers");

        $response->assertOk()
                 ->assertJsonStructure(['data'])
                 ->assertJsonStructure(['data' => [
                     '*' => ['engineer_id', 'engineer_name', 'score', 'score_badge',
                             'skill_match_score', 'price_match_score',
                             'location_match_score', 'availability_match_score'],
                 ]]);
    }

    public function test_recommend_engineers_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/matching/projects/1/engineers');

        $response->assertUnauthorized();
    }

    public function test_recommend_engineers_returns_404_for_unknown_project(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/v1/matching/projects/99999/engineers');

        $response->assertNotFound();
    }

    public function test_recommend_engineers_only_includes_public_engineers(): void
    {
        $this->actingAsUser();

        $project = PublicProject::factory()->published()->create();

        $publicEngineer = Engineer::factory()->create(['name' => '公開技術者']);
        EngineerProfile::create([
            'tenant_id'   => $publicEngineer->tenant_id,
            'engineer_id' => $publicEngineer->id,
            'is_public'   => true,
        ]);

        $privateEngineer = Engineer::factory()->create(['name' => '非公開技術者']);
        EngineerProfile::create([
            'tenant_id'   => $privateEngineer->tenant_id,
            'engineer_id' => $privateEngineer->id,
            'is_public'   => false,
        ]);

        $response = $this->getJson("/api/v1/matching/projects/{$project->id}/engineers");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('engineer_name');
        $this->assertTrue($names->contains('公開技術者'));
        $this->assertFalse($names->contains('非公開技術者'));
    }

    public function test_recommend_engineers_returns_404_for_other_tenant_project(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $otherUser = \App\Models\User::factory()->create();
        $otherProject = (new PublicProject)->forceFill([
            'posted_by_user_id' => $otherUser->id,
            'title'             => '他テナント案件',
            'status'            => 'open',
            'published_at'      => now()->subDay(),
            'tenant_id'         => $otherTenant->id,
        ]);
        $otherProject->save();

        $response = $this->getJson("/api/v1/matching/projects/{$otherProject->id}/engineers");

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // recommendProjects（技術者→案件）
    // ───────────────────────────────────────────

    public function test_recommend_projects_returns_scored_list(): void
    {
        $this->actingAsUser();

        $engineer = Engineer::factory()->create();
        EngineerProfile::create([
            'tenant_id'   => $engineer->tenant_id,
            'engineer_id' => $engineer->id,
            'is_public'   => true,
            'work_style'  => 'remote',
        ]);

        PublicProject::factory()->published()->create();

        $response = $this->getJson("/api/v1/matching/engineers/{$engineer->id}/projects");

        $response->assertOk()
                 ->assertJsonStructure(['data' => [
                     '*' => ['project_id', 'project_title', 'score', 'score_badge', 'required_skills'],
                 ]]);
    }

    public function test_recommend_projects_returns_404_for_unknown_engineer(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/v1/matching/engineers/99999/projects');

        $response->assertNotFound();
    }

    public function test_recommend_projects_returns_404_for_other_tenant_engineer(): void
    {
        $this->actingAsUser();

        $otherTenant  = Tenant::factory()->create();
        $otherEngineer = (new Engineer)->forceFill([
            'name'      => '他テナント技術者',
            'tenant_id' => $otherTenant->id,
        ]);
        $otherEngineer->save();

        $response = $this->getJson("/api/v1/matching/engineers/{$otherEngineer->id}/projects");

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // scoreDetail（スコア詳細 + AI説明文）
    // ───────────────────────────────────────────

    public function test_score_detail_returns_scores_and_explanation(): void
    {
        $this->actingAsUser();

        // MatchingService をモックして Claude API 呼び出しをバイパス
        $this->mock(MatchingService::class, function ($mock) {
            $scores = [
                'score'                    => 75,
                'skill_match_score'        => 80.0,
                'price_match_score'        => 70.0,
                'location_match_score'     => 100.0,
                'availability_match_score' => 50.0,
            ];
            $mock->shouldReceive('calculate')->once()->andReturn($scores);
            $mock->shouldReceive('explainScore')->once()->andReturn('スキル適合度が高く、単価帯も一致しています。');
        });

        $project  = PublicProject::factory()->published()->create();
        $engineer = Engineer::factory()->create();
        EngineerProfile::create([
            'tenant_id'   => $engineer->tenant_id,
            'engineer_id' => $engineer->id,
            'is_public'   => true,
        ]);

        $response = $this->getJson("/api/v1/matching/projects/{$project->id}/engineers/{$engineer->id}");

        $response->assertOk()
                 ->assertJsonPath('data.score', 75)
                 ->assertJsonPath('data.explanation', 'スキル適合度が高く、単価帯も一致しています。')
                 ->assertJsonStructure(['data' => [
                     'score', 'skill_match_score', 'price_match_score',
                     'location_match_score', 'availability_match_score', 'explanation',
                 ]]);
    }

    public function test_score_detail_returns_404_for_unknown_project(): void
    {
        $this->actingAsUser();

        $engineer = Engineer::factory()->create();

        $response = $this->getJson("/api/v1/matching/projects/99999/engineers/{$engineer->id}");

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // skills（スキルマスタ）
    // ───────────────────────────────────────────

    public function test_skills_returns_all_skills(): void
    {
        $this->actingAsUser();

        Skill::create(['name' => 'PHP',   'category' => 'language']);
        Skill::create(['name' => 'MySQL', 'category' => 'database']);

        $response = $this->getJson('/api/v1/matching/skills');

        $response->assertOk()
                 ->assertJsonStructure(['data' => [['id', 'name', 'category']]]);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_skills_filters_by_category(): void
    {
        $this->actingAsUser();

        Skill::create(['name' => 'PHP',    'category' => 'language']);
        Skill::create(['name' => 'Laravel','category' => 'framework']);

        $response = $this->getJson('/api/v1/matching/skills?category=language');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('PHP', $response->json('data.0.name'));
    }

    public function test_skills_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/matching/skills');

        $response->assertUnauthorized();
    }

    // ───────────────────────────────────────────
    // storeSkill（スキル登録）
    // ───────────────────────────────────────────

    public function test_store_skill_creates_skill(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/matching/skills', [
            'name'     => 'Go',
            'category' => 'language',
        ]);

        $response->assertCreated()
                 ->assertJsonPath('data.name', 'Go')
                 ->assertJsonPath('data.category', 'language');

        $this->assertDatabaseHas('skills', ['name' => 'Go']);
    }

    public function test_store_skill_requires_name(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/matching/skills', []);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['name']);
    }

    public function test_store_skill_rejects_duplicate_name(): void
    {
        $this->actingAsUser();

        Skill::create(['name' => '既存スキル', 'category' => 'other']);

        $response = $this->postJson('/api/v1/matching/skills', [
            'name' => '既存スキル',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['name']);
    }

    public function test_store_skill_validates_category_enum(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/matching/skills', [
            'name'     => '新スキル',
            'category' => 'invalid_category',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['category']);
    }
}
