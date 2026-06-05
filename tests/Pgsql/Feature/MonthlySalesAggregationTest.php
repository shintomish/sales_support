<?php

namespace Tests\Pgsql\Feature;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\MonthlySalesDetail;
use App\Models\SesContract;
use App\Scopes\TenantScope;
use App\Services\MonthlySalesAggregationService;
use Carbon\Carbon;
use Tests\Pgsql\PgsqlTestCase;

/**
 * MonthlySalesAggregationService の集計を PostgreSQL で検証する (docs/460)。
 *
 * 確定設計を pin する:
 *  - 月の判定 = 契約期間ベース (start<=月末 AND (end>=月初 OR end NULL))
 *  - 月またぎ = 月単位粗計上 (按分なし・各月に全額)
 *  - cross-tenant (tenantId=null=全テナント / 指定時=当該テナントのみ)
 *  - 再集計の冪等性 (delete→insert)
 */
class MonthlySalesAggregationTest extends PgsqlTestCase
{
    private function makeContract(int $tenantId, array $attrs): SesContract
    {
        $customer = Customer::factory()->create(['tenant_id' => $tenantId]);
        $deal = Deal::factory()->create([
            'tenant_id' => $tenantId, 'customer_id' => $customer->id, 'deal_type' => 'ses',
        ]);
        return SesContract::create(array_merge([
            'tenant_id' => $tenantId,
            'deal_id'   => $deal->id,
        ], $attrs));
    }

    public function test_contract_period_overlap_decides_month_inclusion(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        // 当月(2026-05)に重なる
        $this->makeContract($tenantId, [
            'income_amount' => 500000, 'billing_plus_29' => 300000, 'profit' => 200000,
            'contract_period_start' => '2026-04-15', 'contract_period_end' => '2026-06-10',
        ]);
        // 当月より前に終了 → 含まれない
        $this->makeContract($tenantId, [
            'income_amount' => 999999, 'profit' => 999999,
            'contract_period_start' => '2026-01-01', 'contract_period_end' => '2026-04-30',
        ]);
        // 当月より後に開始 → 含まれない
        $this->makeContract($tenantId, [
            'income_amount' => 888888, 'profit' => 888888,
            'contract_period_start' => '2026-06-01', 'contract_period_end' => '2026-12-31',
        ]);
        // end NULL (継続中) で start が当月以前 → 含まれる
        $this->makeContract($tenantId, [
            'income_amount' => 100000, 'billing_plus_29' => 60000, 'profit' => 40000,
            'contract_period_start' => '2026-03-01', 'contract_period_end' => null,
        ]);

        $result = app(MonthlySalesAggregationService::class)->aggregateMonth(2026, 5, $tenantId);

        $this->assertSame(2, $result['detail_count']);
        $this->assertSame(600000.0, $result['total_revenue']); // 500000 + 100000
        $this->assertSame(360000.0, $result['total_cost']);    // 300000 + 60000
        $this->assertSame(240000.0, $result['total_profit']);  // 200000 + 40000
    }

    public function test_multi_month_contract_is_counted_gross_in_each_month(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        // 3ヶ月(4〜6月)にまたがる契約。按分せず各月に全額計上されること。
        $this->makeContract($tenantId, [
            'income_amount' => 700000, 'billing_plus_29' => 500000, 'profit' => 200000,
            'contract_period_start' => '2026-04-10', 'contract_period_end' => '2026-06-20',
        ]);

        $svc = app(MonthlySalesAggregationService::class);
        foreach ([4, 5, 6] as $m) {
            $r = $svc->aggregateMonth(2026, $m, $tenantId);
            $this->assertSame(1, $r['detail_count'], "month $m should include the contract");
            $this->assertSame(700000.0, $r['total_revenue'], "month $m gross (no proration)");
        }
        // 範囲外の 3月・7月には計上されない
        $this->assertSame(0, $svc->aggregateMonth(2026, 3, $tenantId)['detail_count']);
        $this->assertSame(0, $svc->aggregateMonth(2026, 7, $tenantId)['detail_count']);
    }

    public function test_cross_tenant_scope(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;
        $other    = $this->createUserInAnotherTenant();

        $this->makeContract($tenantId, [
            'income_amount' => 500000, 'profit' => 100000,
            'contract_period_start' => '2026-05-01', 'contract_period_end' => '2026-05-31',
        ]);
        $this->makeContract($other->tenant_id, [
            'income_amount' => 300000, 'profit' => 80000,
            'contract_period_start' => '2026-05-01', 'contract_period_end' => '2026-05-31',
        ]);

        $svc = app(MonthlySalesAggregationService::class);

        // 自テナント指定 → 自分のみ
        $mine = $svc->aggregateMonth(2026, 5, $tenantId);
        $this->assertSame(1, $mine['detail_count']);
        $this->assertSame(500000.0, $mine['total_revenue']);

        // 全テナント横断 (null) → 両方
        $all = $svc->aggregateMonth(2026, 5, null);
        $this->assertSame(2, $all['detail_count']);
        $this->assertSame(800000.0, $all['total_revenue']);
    }

    public function test_fiscal_year_helpers(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenant = $this->authUser->tenant;
        $tenant->update(['fiscal_year_end_month' => 9, 'first_period_fiscal_year' => 2011]);
        $tenant->refresh();

        // 9月決算: 2025-10〜2026-09 = 2026年度 = 16期
        $this->assertSame(2026, $tenant->currentFiscalYear(\Carbon\Carbon::parse('2026-06-05')));
        $this->assertSame(2027, $tenant->currentFiscalYear(\Carbon\Carbon::parse('2026-11-01'))); // 10月超で翌年度
        $this->assertSame(16, $tenant->periodFor(2026));

        $months = $tenant->fiscalYearMonths(2026);
        $this->assertCount(12, $months);
        $this->assertSame(['year' => 2025, 'month' => 10], $months[0]);
        $this->assertSame(['year' => 2026, 'month' => 9], $months[11]);
    }

    public function test_recompute_is_idempotent(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        $this->makeContract($tenantId, [
            'income_amount' => 400000, 'profit' => 90000,
            'contract_period_start' => '2026-05-01', 'contract_period_end' => '2026-05-31',
        ]);

        $svc = app(MonthlySalesAggregationService::class);
        $svc->aggregateMonth(2026, 5, $tenantId);
        $svc->aggregateMonth(2026, 5, $tenantId); // 2回目

        // 重複行が増えていないこと
        $count = MonthlySalesDetail::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)->where('year', 2026)->where('month', 5)->count();
        $this->assertSame(1, $count);
    }
}
