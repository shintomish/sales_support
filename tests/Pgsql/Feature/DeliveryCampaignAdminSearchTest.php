<?php

namespace Tests\Pgsql\Feature;

use App\Models\DeliveryCampaign;
use Tests\Pgsql\PgsqlTestCase;

/**
 * /api/v1/delivery-campaigns?search=xxx の ilike 検索を PostgreSQL で検証する。
 * 検索対象: subject / projectMailSource.title (whereHas) / sendHistories.email-name (whereHas)
 * （Issue #22）
 */
class DeliveryCampaignAdminSearchTest extends PgsqlTestCase
{
    public function test_index_searches_by_subject_with_ilike(): void
    {
        $this->actingAsUser();

        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'subject'   => 'Laravel エンジニア案内',
        ]);
        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'subject'   => 'Java エンジニア案内',
        ]);

        // 「laravel」 (小文字) で ilike → 「Laravel エンジニア案内」 にヒット
        $res = $this->getJson('/api/v1/delivery-campaigns?search=' . urlencode('laravel'));

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Laravel エンジニア案内', $res->json('data.0.subject'));
    }
}
