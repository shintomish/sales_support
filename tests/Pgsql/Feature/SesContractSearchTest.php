<?php

namespace Tests\Pgsql\Feature;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\SesContract;
use Tests\Pgsql\PgsqlTestCase;

/**
 * /api/v1/ses-contracts?search=xxx の ilike 検索を PostgreSQL で検証する。
 * 検索対象: deals.title / sesContract.engineer_name(whereHas) / customer.company_name(whereHas)
 * （Issue #22）
 */
class SesContractSearchTest extends PgsqlTestCase
{
    public function test_index_searches_by_deal_title_with_ilike(): void
    {
        // tenant_admin で実行（tenant_user だと自分の deal のみ表示される resolveUserFilter 仕様のため）
        $this->actingAsUser(['role' => 'tenant_admin']);

        $customer = Customer::factory()->create([
            'tenant_id'    => $this->authUser->tenant_id,
            'company_name' => '株式会社A',
        ]);

        $deal1 = Deal::factory()->create([
            'tenant_id'   => $this->authUser->tenant_id,
            'customer_id' => $customer->id,
            'deal_type'   => 'ses',
            'title'       => 'Laravel エンジニア',
        ]);
        SesContract::create([
            'tenant_id'     => $this->authUser->tenant_id,
            'deal_id'       => $deal1->id,
            'engineer_name' => '山田太郎',
        ]);

        $deal2 = Deal::factory()->create([
            'tenant_id'   => $this->authUser->tenant_id,
            'customer_id' => $customer->id,
            'deal_type'   => 'ses',
            'title'       => 'Java エンジニア',
        ]);
        SesContract::create([
            'tenant_id'     => $this->authUser->tenant_id,
            'deal_id'       => $deal2->id,
            'engineer_name' => '鈴木花子',
        ]);

        // 「laravel」 (小文字) で ilike → 「Laravel エンジニア」 にヒット
        $res = $this->getJson('/api/v1/ses-contracts?search=' . urlencode('laravel'));

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('project_name')->all();
        $this->assertContains('Laravel エンジニア', $titles);
        $this->assertNotContains('Java エンジニア', $titles);
    }
}
