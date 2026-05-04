<?php

namespace Tests\Pgsql\Feature;

use App\Models\Skill;
use Tests\Pgsql\PgsqlTestCase;

/**
 * /api/v1/matching/skills?search=xxx の ilike 検索を PostgreSQL で検証する。
 * （Issue #22: Matching / SesContract / DeliveryCampaign の admin search 追加カバレッジ）
 */
class MatchingSkillsSearchTest extends PgsqlTestCase
{
    public function test_skills_search_uses_ilike_case_insensitive(): void
    {
        $this->actingAsUser();
        Skill::create(['name' => 'Laravel', 'category' => 'framework']);
        Skill::create(['name' => 'Django', 'category' => 'framework']);
        Skill::create(['name' => 'PostgreSQL', 'category' => 'database']);

        // 小文字検索でも Laravel にヒット（ilike: case-insensitive）
        $res = $this->getJson('/api/v1/matching/skills?search=' . urlencode('laravel'));

        $res->assertOk();
        $names = collect($res->json('data'))->pluck('name')->all();
        $this->assertContains('Laravel', $names);
        $this->assertNotContains('Django', $names);
        $this->assertNotContains('PostgreSQL', $names);
    }
}
