<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use Tests\Pgsql\PgsqlTestCase;

/**
 * Email 検索の ilike 動作（subject / from_address / from_name / body_text）を
 * PostgreSQL で検証する。SQLite では ilike が使えないため Pgsql suite で実行。
 */
class EmailSearchTest extends PgsqlTestCase
{
    public function test_index_searches_by_subject_case_insensitive(): void
    {
        $this->actingAsUser();
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'subject' => 'Java案件のご紹介']);
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'subject' => '別件のお知らせ']);

        // ilike: 大文字小文字を区別しないので "java"（小文字）でもヒット
        $res = $this->getJson('/api/v1/emails?search=' . urlencode('java'));

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Java案件のご紹介', $res->json('data.0.subject'));
    }
}
