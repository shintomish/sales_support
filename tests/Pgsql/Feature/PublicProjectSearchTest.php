<?php

namespace Tests\Pgsql\Feature;

use App\Models\PublicProject;
use Tests\Pgsql\PgsqlTestCase;

/**
 * PublicProject 検索の ilike 動作（title / description の両カラム OR 検索）を検証する。
 */
class PublicProjectSearchTest extends PgsqlTestCase
{
    public function test_index_searches_by_title_or_description_with_ilike(): void
    {
        $this->actingAsUser();

        PublicProject::factory()->create([
            'title'       => 'Laravel APIエンジニア募集',
            'description' => 'PHP案件です',
        ]);
        PublicProject::factory()->create([
            'title'       => '別タイトル',
            'description' => 'Laravel での開発が必要',
        ]);
        PublicProject::factory()->create([
            'title'       => 'Java 案件',
            'description' => 'Spring Boot',
        ]);

        $res = $this->getJson('/api/v1/public-projects?search=' . urlencode('laravel'));

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertCount(2, $titles, 'title または description のいずれかにヒット');
        $this->assertContains('Laravel APIエンジニア募集', $titles);
        $this->assertContains('別タイトル', $titles);
    }
}
