<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use Tests\Pgsql\PgsqlTestCase;

/**
 * /api/v1/emails の本文検索 (search_body) の挙動を test-postgres で検証する。
 *
 * 本番は pgroonga 全文索引 (emails_pgroonga_fts_idx) の &@~ で高速検索するが、
 * test-postgres は pgroonga 非対応のため PGROONGA_ENABLED=false で ILIKE フォールバック経路を通る。
 * ここではその分岐 (本文ありなら本文も対象 / 無しなら件名・差出人のみ) を pin する。
 * ※ ilike は postgres 専用構文のため sqlite 既定スイートではなく Pgsql スイートに置く。
 */
class EmailBodySearchTest extends PgsqlTestCase
{
    public function test_body_search_returns_body_text_matches(): void
    {
        $this->actingAsUser();
        $tenantId = $this->authUser->tenant_id;
        $hit = Email::factory()->create([
            'tenant_id' => $tenantId, 'category' => null,
            'subject' => 'aaa', 'body_text' => 'これは検索対象キーワードを含む本文',
        ]);
        Email::factory()->create([
            'tenant_id' => $tenantId, 'category' => null,
            'subject' => 'bbb', 'body_text' => '無関係な本文',
        ]);

        $res = $this->getJson('/api/v1/emails?search=' . urlencode('検索対象キーワード') . '&search_body=1');

        $res->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $this->assertContains($hit->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_search_without_body_excludes_body_only_matches(): void
    {
        $this->actingAsUser();
        $tenantId = $this->authUser->tenant_id;
        Email::factory()->create([
            'tenant_id' => $tenantId, 'category' => null,
            'subject' => '件名XXX', 'body_text' => '本文だけのユニーク語ZZZ',
        ]);

        // search_body を付けない → 本文のみ一致は返さない
        $res = $this->getJson('/api/v1/emails?search=' . urlencode('ユニーク語ZZZ'));

        $res->assertOk();
        $this->assertCount(0, $res->json('data'));
    }
}
