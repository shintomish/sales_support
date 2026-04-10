<?php

namespace Tests\Feature\Api;

use App\Models\Engineer;
use App\Models\EngineerProfile;
use App\Models\EngineerSkill;
use App\Models\Skill;
use App\Models\Tenant;
use Tests\TestCase;

class EngineerControllerTest extends TestCase
{
    // ───────────────────────────────────────────
    // index
    // ───────────────────────────────────────────

    public function test_index_returns_paginated_engineers(): void
    {
        $this->actingAsUser();

        Engineer::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/engineers');

        $response->assertOk()
                 ->assertJsonStructure(['data', 'meta']);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/engineers');

        $response->assertUnauthorized();
    }

    public function test_index_only_returns_own_tenant_engineers(): void
    {
        $this->actingAsUser();

        Engineer::factory()->create(['name' => '自テナント技術者']);

        $otherTenant = Tenant::factory()->create();
        (new Engineer)->forceFill([
            'name'      => '他テナント技術者',
            'tenant_id' => $otherTenant->id,
        ])->save();

        $response = $this->getJson('/api/v1/engineers');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('自テナント技術者', $response->json('data.0.name'));
    }

    public function test_index_searches_by_name(): void
    {
        $this->skipIfSqlite(); // ilike は PostgreSQL 固有
        $this->actingAsUser();

        Engineer::factory()->create(['name' => '山田太郎']);
        Engineer::factory()->create(['name' => '鈴木花子']);

        $response = $this->getJson('/api/v1/engineers?search=山田');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('山田太郎', $response->json('data.0.name'));
    }

    public function test_index_filters_by_skill(): void
    {
        $this->actingAsUser();

        $skill     = Skill::create(['name' => 'PHP', 'category' => 'backend']);
        $engineer1 = Engineer::factory()->create(['name' => 'PHPエンジニア']);
        $engineer2 = Engineer::factory()->create(['name' => 'Javaエンジニア']);

        EngineerSkill::create([
            'tenant_id'  => $engineer1->tenant_id,
            'engineer_id' => $engineer1->id,
            'skill_id'    => $skill->id,
        ]);

        $response = $this->getJson("/api/v1/engineers?skill_id={$skill->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('PHPエンジニア', $response->json('data.0.name'));
    }

    // ───────────────────────────────────────────
    // show
    // ───────────────────────────────────────────

    public function test_show_returns_engineer_detail(): void
    {
        $this->actingAsUser();

        $engineer = Engineer::factory()->create(['name' => '詳細テスト技術者']);

        $response = $this->getJson("/api/v1/engineers/{$engineer->id}");

        $response->assertOk()
                 ->assertJsonPath('data.name', '詳細テスト技術者')
                 ->assertJsonStructure(['data' => ['id', 'name', 'profile', 'skills']]);
    }

    public function test_show_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $engineer    = Engineer::withoutGlobalScopes()->create([
            'name'      => '他テナント技術者',
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->getJson("/api/v1/engineers/{$engineer->id}");

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // store
    // ───────────────────────────────────────────

    public function test_store_creates_engineer_with_profile_and_skills(): void
    {
        $this->actingAsUser();

        $skill = Skill::create(['name' => 'Laravel', 'category' => 'backend']);

        $response = $this->postJson('/api/v1/engineers', [
            'name'              => '新規技術者',
            'email'             => 'test@example.com',
            'affiliation'       => '株式会社テスト',
            'age'               => 30,
            'gender'            => 'male',
            'affiliation_type'  => 'bp',
            'available_from'    => '2026-05-01',
            'availability_status' => 'available',
            'work_style'        => 'remote',
            'is_public'         => false,
            'skills'            => [
                [
                    'skill_id'          => $skill->id,
                    'experience_years'  => 3.5,
                    'proficiency_level' => 4,
                ],
            ],
        ]);

        $response->assertCreated()
                 ->assertJsonPath('data.name', '新規技術者')
                 ->assertJsonPath('data.profile.work_style', 'remote')
                 ->assertJsonPath('data.skills.0.skill_name', 'Laravel');

        $this->assertDatabaseHas('engineers', ['name' => '新規技術者']);
        $this->assertDatabaseHas('engineer_profiles', ['availability_status' => 'available']);
        $this->assertDatabaseHas('engineer_skills', ['skill_id' => $skill->id, 'experience_years' => 3.5]);
    }

    public function test_store_requires_name(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/engineers', []);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['name']);
    }

    public function test_store_validates_gender_enum(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/engineers', [
            'name'   => 'テスト技術者',
            'gender' => 'invalid_gender',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['gender']);
    }

    public function test_store_validates_affiliation_type_enum(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/engineers', [
            'name'             => 'テスト技術者',
            'affiliation_type' => 'unknown_type',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['affiliation_type']);
    }

    // ───────────────────────────────────────────
    // update
    // ───────────────────────────────────────────

    public function test_update_modifies_engineer_fields(): void
    {
        $this->actingAsUser();

        $engineer = Engineer::factory()->create(['name' => '更新前の名前']);

        $response = $this->putJson("/api/v1/engineers/{$engineer->id}", [
            'name'        => '更新後の名前',
            'affiliation' => '新所属会社',
        ]);

        $response->assertOk()
                 ->assertJsonPath('data.name', '更新後の名前');

        $this->assertDatabaseHas('engineers', [
            'id'          => $engineer->id,
            'name'        => '更新後の名前',
            'affiliation' => '新所属会社',
        ]);
    }

    public function test_update_replaces_skills_when_skills_key_is_present(): void
    {
        $this->actingAsUser();

        $skill1   = Skill::create(['name' => '旧スキル', 'category' => 'other']);
        $skill2   = Skill::create(['name' => '新スキル', 'category' => 'other']);
        $engineer = Engineer::factory()->create();

        EngineerSkill::create([
            'tenant_id'   => $engineer->tenant_id,
            'engineer_id' => $engineer->id,
            'skill_id'    => $skill1->id,
        ]);

        $response = $this->putJson("/api/v1/engineers/{$engineer->id}", [
            'name'   => $engineer->name,
            'skills' => [
                ['skill_id' => $skill2->id, 'experience_years' => 2],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('engineer_skills', ['skill_id' => $skill1->id, 'engineer_id' => $engineer->id]);
        $this->assertDatabaseHas('engineer_skills', ['skill_id' => $skill2->id, 'engineer_id' => $engineer->id]);
    }

    public function test_update_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $engineer    = Engineer::withoutGlobalScopes()->create([
            'name'      => '他テナント技術者',
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->putJson("/api/v1/engineers/{$engineer->id}", [
            'name' => '変更しようとする',
        ]);

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // destroy
    // ───────────────────────────────────────────

    public function test_destroy_soft_deletes_engineer(): void
    {
        $this->actingAsUser();

        $engineer = Engineer::factory()->create();

        $response = $this->deleteJson("/api/v1/engineers/{$engineer->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('engineers', ['id' => $engineer->id]);
    }

    public function test_destroy_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $engineer    = Engineer::withoutGlobalScopes()->create([
            'name'      => '他テナント技術者',
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->deleteJson("/api/v1/engineers/{$engineer->id}");

        $response->assertNotFound();
    }
}
