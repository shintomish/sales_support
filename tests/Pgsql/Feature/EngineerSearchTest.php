<?php

namespace Tests\Pgsql\Feature;

use App\Models\Engineer;
use Tests\Pgsql\PgsqlTestCase;

/**
 * Engineer 検索の ilike 動作を PostgreSQL で検証する。
 * 元は EngineerControllerTest::test_index_searches_by_name で skipIfSqlite されていた。
 */
class EngineerSearchTest extends PgsqlTestCase
{
    public function test_index_searches_by_name(): void
    {
        $this->actingAsUser();

        Engineer::factory()->create(['name' => '山田太郎']);
        Engineer::factory()->create(['name' => '鈴木花子']);

        $response = $this->getJson('/api/v1/engineers?search=山田');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('山田太郎', $response->json('data.0.name'));
    }
}
