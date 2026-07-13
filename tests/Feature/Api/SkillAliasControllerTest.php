<?php

namespace Tests\Feature\Api;

use App\Models\SkillAlias;
use App\Services\SkillDictionary;
use Tests\TestCase;

/**
 * スキル同義語辞書 管理API のテスト。
 *  - 閲覧・追加は全員 / 削除・改名は管理者のみ(403)
 *  - 重複 alias は 422
 *  - 書き換え後は SkillDictionary キャッシュが破棄され即時反映
 */
class SkillAliasControllerTest extends TestCase
{
    public function test_一覧は一般ユーザーでも閲覧できる(): void
    {
        $this->actingAsUser(); // tenant_user
        $this->getJson('/api/v1/skill-aliases')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_一般ユーザーも追加できる(): void
    {
        $this->actingAsUser(['role' => 'tenant_user']);
        $this->postJson('/api/v1/skill-aliases', ['canonical' => 'Go', 'alias' => 'Golang'])
            ->assertStatus(201);
        $this->assertDatabaseHas('skill_aliases', ['alias' => 'Golang']);
    }

    public function test_一般ユーザーは削除できない(): void
    {
        $this->actingAsUser(['role' => 'tenant_user']);
        $row = SkillAlias::create(['canonical' => 'Rust', 'alias' => 'rustlang']);
        $this->deleteJson("/api/v1/skill-aliases/{$row->id}")->assertStatus(403);
        $this->assertDatabaseHas('skill_aliases', ['id' => $row->id]);
    }

    public function test_管理者は追加でき辞書に即時反映される(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $this->postJson('/api/v1/skill-aliases', ['canonical' => 'Go', 'alias' => 'Golang'])
            ->assertStatus(201);
        $this->postJson('/api/v1/skill-aliases', ['canonical' => 'Go', 'alias' => 'Go'])
            ->assertStatus(201);

        // キャッシュ破棄済み → 新しい別名で名寄せされる
        $dict = app(SkillDictionary::class);
        $this->assertSame($dict->canonical('Golang'), $dict->canonical('Go'));
    }

    public function test_重複aliasは422(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        SkillAlias::create(['canonical' => 'Java', 'alias' => 'JAVA8']);
        $this->postJson('/api/v1/skill-aliases', ['canonical' => 'Java', 'alias' => 'JAVA8'])
            ->assertStatus(422);
    }

    public function test_管理者は削除できる(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $row = SkillAlias::create(['canonical' => 'Rust', 'alias' => 'rustlang']);
        $this->deleteJson("/api/v1/skill-aliases/{$row->id}")->assertOk();
        $this->assertDatabaseMissing('skill_aliases', ['id' => $row->id]);
    }
}
