<?php

namespace Tests\Feature\Api;

use App\Models\Email;
use App\Models\EngineerMailSource;
use App\Models\PublicProject;
use App\Models\ProjectRequiredSkill;
use App\Models\Skill;
use Tests\TestCase;

class EngineerMailControllerTest extends TestCase
{
    // ───────────────────────────────────────────
    // index
    // ───────────────────────────────────────────

    public function test_index_returns_paginated_list(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        EngineerMailSource::factory()->count(3)->create([
            'email_id' => $email->id,
            'status'   => 'new',
        ]);

        $response = $this->getJson('/api/v1/engineer-mails');

        $response->assertOk()
                 ->assertJsonStructure(['data', 'total', 'per_page']);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/engineer-mails');

        $response->assertUnauthorized();
    }

    public function test_index_filters_by_status(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        EngineerMailSource::factory()->create(['email_id' => $email->id, 'status' => 'new']);
        EngineerMailSource::factory()->create(['email_id' => $email->id, 'status' => 'registered']);

        $response = $this->getJson('/api/v1/engineer-mails?status=registered');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('registered', $response->json('data.0.status'));
    }

    // ───────────────────────────────────────────
    // show
    // ───────────────────────────────────────────

    public function test_show_returns_detail(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $ems   = EngineerMailSource::factory()->create([
            'email_id' => $email->id,
            'name'     => '山田太郎',
        ]);

        $response = $this->getJson("/api/v1/engineer-mails/{$ems->id}");

        $response->assertOk()
                 ->assertJsonPath('name', '山田太郎');
    }

    public function test_show_returns_404_for_unknown(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/v1/engineer-mails/99999');

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // update
    // ───────────────────────────────────────────

    public function test_update_modifies_extracted_info(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $ems   = EngineerMailSource::factory()->create([
            'email_id' => $email->id,
            'name'     => '旧名前',
        ]);

        $response = $this->putJson("/api/v1/engineer-mails/{$ems->id}", [
            'name'            => '新名前',
            'nearest_station' => '渋谷駅',
            'skills'          => ['PHP', 'Laravel'],
        ]);

        $response->assertOk()
                 ->assertJsonPath('name', '新名前')
                 ->assertJsonPath('nearest_station', '渋谷駅');
    }

    // ───────────────────────────────────────────
    // updateStatus
    // ───────────────────────────────────────────

    public function test_update_status_changes_status(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $ems   = EngineerMailSource::factory()->create([
            'email_id' => $email->id,
            'status'   => 'new',
        ]);

        $response = $this->putJson("/api/v1/engineer-mails/{$ems->id}/status", [
            'status' => 'excluded',
        ]);

        $response->assertOk()
                 ->assertJsonPath('status', 'excluded');
    }

    public function test_update_status_rejects_invalid_value(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $ems   = EngineerMailSource::factory()->create(['email_id' => $email->id]);

        $response = $this->putJson("/api/v1/engineer-mails/{$ems->id}/status", [
            'status' => 'invalid_status',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['status']);
    }

    // ───────────────────────────────────────────
    // P1: registerEngineer
    // ───────────────────────────────────────────

    public function test_register_engineer_creates_engineer_from_ems(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create(['from_address' => 'bp@example.com']);
        $ems   = EngineerMailSource::factory()->create([
            'email_id'         => $email->id,
            'name'             => '田中花子',
            'affiliation_type' => 'bp',
            'nearest_station'  => '新宿駅',
            'skills'           => ['PHP', 'Laravel', 'MySQL'],
            'status'           => 'new',
        ]);

        $response = $this->postJson("/api/v1/engineer-mails/{$ems->id}/register-engineer");

        $response->assertCreated()
                 ->assertJsonPath('message', 'Engineerマスタに登録しました');

        $this->assertDatabaseHas('engineers', [
            'name'             => '田中花子',
            'affiliation_type' => 'bp',
            'nearest_station'  => '新宿駅',
            'affiliation_email'=> 'bp@example.com',
        ]);

        // スキルが登録されている
        $this->assertDatabaseHas('skills', ['name' => 'PHP']);
        $this->assertDatabaseHas('skills', ['name' => 'Laravel']);

        // EMSのステータスが'registered'に変わっている
        $this->assertDatabaseHas('engineer_mail_sources', [
            'id'     => $ems->id,
            'status' => 'registered',
        ]);
    }

    public function test_register_engineer_returns_422_if_already_registered(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $ems   = EngineerMailSource::factory()->create([
            'email_id' => $email->id,
            'status'   => 'registered',
        ]);

        $response = $this->postJson("/api/v1/engineer-mails/{$ems->id}/register-engineer");

        $response->assertUnprocessable()
                 ->assertJsonPath('message', 'すでに登録済みです');
    }

    public function test_register_engineer_skips_empty_skill_names(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $ems   = EngineerMailSource::factory()->create([
            'email_id' => $email->id,
            'name'     => 'スキルなし技術者',
            'skills'   => ['', ' ', 'Go'],
            'status'   => 'new',
        ]);

        $response = $this->postJson("/api/v1/engineer-mails/{$ems->id}/register-engineer");

        $response->assertCreated();
        $this->assertDatabaseHas('skills', ['name' => 'Go']);
        // 空白スキルは登録されない
        $this->assertDatabaseMissing('skills', ['name' => '']);
        $this->assertDatabaseMissing('skills', ['name' => ' ']);
    }

    // ───────────────────────────────────────────
    // P2: matchedProjects
    // ───────────────────────────────────────────

    public function test_matched_projects_returns_sorted_by_match_score(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $ems   = EngineerMailSource::factory()->create([
            'email_id' => $email->id,
            'skills'   => ['PHP', 'Laravel', 'MySQL'],
        ]);

        // PHP・Laravel・MySQL全てマッチするプロジェクト
        $phpSkill     = Skill::create(['name' => 'PHP',     'category' => 'language']);
        $laravelSkill = Skill::create(['name' => 'Laravel', 'category' => 'framework']);
        $mysqlSkill   = Skill::create(['name' => 'MySQL',   'category' => 'database']);

        $highProject = PublicProject::factory()->published()->create(['title' => '高マッチ案件']);
        ProjectRequiredSkill::create(['project_id' => $highProject->id, 'skill_id' => $phpSkill->id,     'is_required' => true]);
        ProjectRequiredSkill::create(['project_id' => $highProject->id, 'skill_id' => $laravelSkill->id, 'is_required' => true]);
        ProjectRequiredSkill::create(['project_id' => $highProject->id, 'skill_id' => $mysqlSkill->id,   'is_required' => true]);

        // PHPのみマッチするプロジェクト
        $goSkill    = Skill::create(['name' => 'Go', 'category' => 'language']);
        $lowProject = PublicProject::factory()->published()->create(['title' => '低マッチ案件']);
        ProjectRequiredSkill::create(['project_id' => $lowProject->id, 'skill_id' => $phpSkill->id, 'is_required' => true]);
        ProjectRequiredSkill::create(['project_id' => $lowProject->id, 'skill_id' => $goSkill->id,  'is_required' => true]);

        $response = $this->getJson("/api/v1/engineer-mails/{$ems->id}/matched-projects");

        $response->assertOk()
                 ->assertJsonStructure(['data' => [
                     '*' => ['project_id', 'project_title', 'match_score', 'matched_count',
                             'total_skills', 'required_skills'],
                 ]]);

        $data = $response->json('data');
        // 高マッチ案件が先頭
        $this->assertSame('高マッチ案件', $data[0]['project_title']);
        $this->assertSame(100, $data[0]['match_score']);

        // 低マッチ案件
        $this->assertSame('低マッチ案件', $data[1]['project_title']);
        $this->assertSame(50, $data[1]['match_score']);
    }

    public function test_matched_projects_returns_404_for_unknown_ems(): void
    {
        $this->actingAsUser();

        $response = $this->getJson('/api/v1/engineer-mails/99999/matched-projects');

        $response->assertNotFound();
    }

    public function test_matched_projects_returns_empty_when_no_open_projects(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $ems   = EngineerMailSource::factory()->create([
            'email_id' => $email->id,
            'skills'   => ['PHP'],
        ]);

        $response = $this->getJson("/api/v1/engineer-mails/{$ems->id}/matched-projects");

        $response->assertOk()
                 ->assertJsonPath('data', []);
    }

    public function test_matched_projects_is_case_insensitive(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $ems   = EngineerMailSource::factory()->create([
            'email_id' => $email->id,
            'skills'   => ['php', 'laravel'],  // 小文字
        ]);

        $phpSkill = Skill::create(['name' => 'PHP', 'category' => 'language']); // 大文字

        $project = PublicProject::factory()->published()->create(['title' => 'PHP案件']);
        ProjectRequiredSkill::create(['project_id' => $project->id, 'skill_id' => $phpSkill->id, 'is_required' => true]);

        $response = $this->getJson("/api/v1/engineer-mails/{$ems->id}/matched-projects");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(100, $data[0]['match_score']);
    }
}
