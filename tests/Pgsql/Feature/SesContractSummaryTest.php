<?php

namespace Tests\Pgsql\Feature;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\SesContract;
use Carbon\Carbon;
use Tests\Pgsql\PgsqlTestCase;

/**
 * /api/v1/ses-contracts/summary の集計を PostgreSQL で検証する。
 *
 * docs/730 #20 で旧 PHP 集計を 1 SQL (selectRaw COUNT FILTER) に書き換えた回帰防護。
 * cross-tenant 集計漏れと expiring 境界 (今日 / +30 / +31 日) を pin する。
 */
class SesContractSummaryTest extends PgsqlTestCase
{
    public function test_summary_aggregates_only_current_tenant(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $myTenantId = $this->authUser->tenant_id;

        $myCustomer = Customer::factory()->create(['tenant_id' => $myTenantId]);

        // 自テナント: deal × 2 (active 1, expiring 1)
        $dealA = Deal::factory()->create([
            'tenant_id' => $myTenantId, 'customer_id' => $myCustomer->id,
            'deal_type' => 'ses', 'status' => '稼働中',
        ]);
        SesContract::create([
            'tenant_id' => $myTenantId, 'deal_id' => $dealA->id,
            'income_amount' => 800000, 'profit' => 200000,
            'contract_period_end' => Carbon::today()->addDays(15)->toDateString(),
        ]);
        $dealB = Deal::factory()->create([
            'tenant_id' => $myTenantId, 'customer_id' => $myCustomer->id,
            'deal_type' => 'ses', 'status' => '提案',
        ]);
        SesContract::create([
            'tenant_id' => $myTenantId, 'deal_id' => $dealB->id,
            'income_amount' => 600000, 'profit' => 150000,
            'contract_period_end' => Carbon::today()->addDays(90)->toDateString(),
        ]);

        // 他テナント: ノイズデータ (集計に混入してはならない)
        $other = $this->createUserInAnotherTenant();
        $otherCustomer = Customer::factory()->create(['tenant_id' => $other->tenant_id]);
        $otherDeal = Deal::factory()->create([
            'tenant_id' => $other->tenant_id, 'customer_id' => $otherCustomer->id,
            'deal_type' => 'ses', 'status' => '稼働中',
        ]);
        SesContract::create([
            'tenant_id' => $other->tenant_id, 'deal_id' => $otherDeal->id,
            'income_amount' => 9999999, 'profit' => 9999999,
            'contract_period_end' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $res = $this->getJson('/api/v1/ses-contracts/summary');

        $res->assertOk();
        $res->assertJson([
            'total_income'   => 1400000.0,   // 800000 + 600000 (自テナントのみ)
            'total_profit'   => 350000.0,    // 200000 + 150000
            'active_count'   => 1,           // 稼働中 1 件 (他テナント含まず)
            'expiring_count' => 1,           // +15 日のみ (+90 日は範囲外)
        ]);
    }

    public function test_expiring_boundary_today_and_30_days_inclusive(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        $makeDeal = function (string $endDate) use ($tenantId, $customer): void {
            $d = Deal::factory()->create([
                'tenant_id' => $tenantId, 'customer_id' => $customer->id,
                'deal_type' => 'ses', 'status' => '提案',
            ]);
            SesContract::create([
                'tenant_id' => $tenantId, 'deal_id' => $d->id,
                'contract_period_end' => $endDate,
            ]);
        };

        // 境界値: -1 日 (期限切れ・除外) / 今日 (含む) / +30 日 (含む) / +31 日 (除外)
        $makeDeal(Carbon::today()->subDays(1)->toDateString());
        $makeDeal(Carbon::today()->toDateString());
        $makeDeal(Carbon::today()->addDays(30)->toDateString());
        $makeDeal(Carbon::today()->addDays(31)->toDateString());

        $res = $this->getJson('/api/v1/ses-contracts/summary');

        $res->assertOk();
        $this->assertSame(2, $res->json('expiring_count'),
            '今日 と +30 日 のみカウントされる (−1 日 と +31 日 は除外)');
    }

    public function test_summary_excludes_soft_deleted_and_non_ses_deals(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);

        // ses + active → カウント対象
        $alive = Deal::factory()->create([
            'tenant_id' => $tenantId, 'customer_id' => $customer->id,
            'deal_type' => 'ses', 'status' => '稼働中',
        ]);
        SesContract::create([
            'tenant_id' => $tenantId, 'deal_id' => $alive->id,
            'income_amount' => 100000, 'profit' => 30000,
        ]);

        // 非 ses (deal_type 'general' 等) → 除外
        $nonSes = Deal::factory()->create([
            'tenant_id' => $tenantId, 'customer_id' => $customer->id,
            'deal_type' => 'general', 'status' => '稼働中',
        ]);
        SesContract::create([
            'tenant_id' => $tenantId, 'deal_id' => $nonSes->id,
            'income_amount' => 500000, 'profit' => 200000,
        ]);

        // soft-deleted → 除外
        $deleted = Deal::factory()->create([
            'tenant_id' => $tenantId, 'customer_id' => $customer->id,
            'deal_type' => 'ses', 'status' => '稼働中',
        ]);
        SesContract::create([
            'tenant_id' => $tenantId, 'deal_id' => $deleted->id,
            'income_amount' => 700000, 'profit' => 300000,
        ]);
        $deleted->delete();

        $res = $this->getJson('/api/v1/ses-contracts/summary');

        $res->assertOk();
        $res->assertJson([
            'total_income' => 100000.0,
            'total_profit' => 30000.0,
            'active_count' => 1,
        ]);
    }
}
