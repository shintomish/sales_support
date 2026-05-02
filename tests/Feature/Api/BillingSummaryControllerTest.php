<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\SesContract;
use App\Models\Tenant;
use App\Models\WorkRecord;
use Tests\TestCase;

class BillingSummaryControllerTest extends TestCase
{
    /** SES案件 + 精算条件 + 当該月の勤務記録 を作るヘルパ */
    private function createSesDeal(array $contract = [], array $record = [], array $deal = [], ?Customer $customer = null): array
    {
        $customer = $customer ?? Customer::factory()->create(['company_name' => 'A社', 'invoice_code' => 'A001']);
        $d = Deal::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'deal_type'   => 'ses',
            'title'       => '案件X',
        ], $deal));

        $contractDefaults = [
            'tenant_id'                   => $d->tenant_id,
            'deal_id'                     => $d->id,
            'income_amount'               => 800000,
            'client_deduction_unit_price' => 5000,
            'client_deduction_hours'      => 140,
            'client_overtime_unit_price'  => 6250,
            'client_overtime_hours'       => 180,
            'engineer_name'               => '山田太郎',
        ];
        SesContract::query()->create(array_merge($contractDefaults, $contract));

        $recordDefaults = [
            'tenant_id'           => $d->tenant_id,
            'deal_id'             => $d->id,
            'year_month'          => '2026-04',
            'actual_hours'        => 160,
            'transportation_fee'  => 5000,
        ];
        WorkRecord::query()->create(array_merge($recordDefaults, $record));

        return ['deal' => $d, 'customer' => $customer];
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/billing-summaries?year_month=2026-04')->assertUnauthorized();
    }

    public function test_index_requires_year_month(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/billing-summaries')->assertStatus(422);
        $this->getJson('/api/v1/billing-summaries?year_month=invalid')->assertStatus(422);
        $this->getJson('/api/v1/billing-summaries?year_month=2026-13')->assertStatus(422);
    }

    public function test_basic_calculation_no_overtime_no_deduction(): void
    {
        $this->actingAsUser();
        $this->createSesDeal();

        $res = $this->getJson('/api/v1/billing-summaries?year_month=2026-04');

        $res->assertOk();
        $items = $res->json('items');
        $this->assertCount(1, $items);
        // 基本800000 + 交通費5000 = 805000、税80500、合計885500
        $this->assertEquals(800000, $items[0]['basic']);
        $this->assertEquals(0,      $items[0]['deduction']);
        $this->assertEquals(0,      $items[0]['overtime']);
        $this->assertEquals(5000,   $items[0]['transportation']);
        $this->assertEquals(805000, $items[0]['subtotal']);
        $this->assertEquals(80500,  $items[0]['tax']);
        $this->assertEquals(885500, $items[0]['total']);
    }

    public function test_deduction_applies_when_actual_hours_below_threshold(): void
    {
        $this->actingAsUser();
        // 控除下限140h、実時間120h → 20h × 5000 = 100,000 控除
        $this->createSesDeal([], ['actual_hours' => 120, 'transportation_fee' => 0]);

        $res = $this->getJson('/api/v1/billing-summaries?year_month=2026-04');

        $items = $res->json('items');
        $this->assertEquals(100000, $items[0]['deduction']);
        $this->assertEquals(0,      $items[0]['overtime']);
        $this->assertEquals(700000, $items[0]['subtotal']);
        $this->assertEquals(770000, $items[0]['total']);
    }

    public function test_overtime_applies_when_actual_hours_above_threshold(): void
    {
        $this->actingAsUser();
        // 超過上限180h、実時間200h → 20h × 6250 = 125,000 超過
        $this->createSesDeal([], ['actual_hours' => 200, 'transportation_fee' => 0]);

        $res = $this->getJson('/api/v1/billing-summaries?year_month=2026-04');

        $items = $res->json('items');
        $this->assertEquals(0,      $items[0]['deduction']);
        $this->assertEquals(125000, $items[0]['overtime']);
        $this->assertEquals(925000, $items[0]['subtotal']);
    }

    public function test_null_actual_hours_skips_deduction_and_overtime(): void
    {
        $this->actingAsUser();
        $this->createSesDeal([], ['actual_hours' => null, 'transportation_fee' => 0]);

        $res = $this->getJson('/api/v1/billing-summaries?year_month=2026-04');

        $items = $res->json('items');
        $this->assertEquals(0,      $items[0]['deduction']);
        $this->assertEquals(0,      $items[0]['overtime']);
        $this->assertEquals(800000, $items[0]['subtotal']);
    }

    public function test_group_by_customer_aggregates_multiple_deals(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['company_name' => 'A社', 'invoice_code' => 'A001']);
        $this->createSesDeal([], [], ['title' => '案件1'], $customer);
        $this->createSesDeal([], ['actual_hours' => 200, 'transportation_fee' => 0], ['title' => '案件2'], $customer);

        $res = $this->getJson('/api/v1/billing-summaries?year_month=2026-04&group=customer');

        $items = $res->json('items');
        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]['deal_count']);
        $this->assertSame($customer->id, $items[0]['customer_id']);
        // 案件1: subtotal 805000, 案件2: subtotal 925000 → 合計 1,730,000
        $this->assertEquals(1730000, $items[0]['subtotal']);
    }

    public function test_does_not_include_other_tenants_records(): void
    {
        $this->actingAsUser();
        $this->createSesDeal();

        // 他テナントの案件
        $otherUser = $this->createUserInAnotherTenant();
        $otherTenant = $otherUser->tenant_id;
        $otherCustomer = (new Customer)->forceFill(['company_name' => 'B社', 'tenant_id' => $otherTenant]);
        $otherCustomer->save();
        $otherDeal = (new Deal)->forceFill([
            'customer_id' => $otherCustomer->id,
            'user_id'     => $otherUser->id,
            'tenant_id'   => $otherTenant,
            'title'       => '他テナント案件',
            'deal_type'   => 'ses',
            'status'      => '新規',
        ]);
        $otherDeal->save();
        (new SesContract)->forceFill([
            'tenant_id'     => $otherTenant,
            'deal_id'       => $otherDeal->id,
            'income_amount' => 999999,
        ])->save();
        (new WorkRecord)->forceFill([
            'tenant_id'    => $otherTenant,
            'deal_id'      => $otherDeal->id,
            'year_month'   => '2026-04',
            'actual_hours' => 160,
        ])->save();

        $res = $this->getJson('/api/v1/billing-summaries?year_month=2026-04');

        $items = $res->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('A社', $items[0]['customer_name']);
    }

    public function test_excludes_non_ses_deals(): void
    {
        $this->actingAsUser();
        $this->createSesDeal([], [], ['deal_type' => 'general']);

        $res = $this->getJson('/api/v1/billing-summaries?year_month=2026-04');

        $this->assertCount(0, $res->json('items'));
    }

    public function test_filters_by_customer_id(): void
    {
        $this->actingAsUser();
        $a = Customer::factory()->create(['company_name' => 'A社']);
        $b = Customer::factory()->create(['company_name' => 'B社']);
        $this->createSesDeal([], [], [], $a);
        $this->createSesDeal([], [], [], $b);

        $res = $this->getJson("/api/v1/billing-summaries?year_month=2026-04&customer_id={$a->id}");

        $items = $res->json('items');
        $this->assertCount(1, $items);
        $this->assertSame($a->id, $items[0]['customer_id']);
    }

    public function test_export_csv_returns_attachment(): void
    {
        $this->actingAsUser();
        $this->createSesDeal();

        $res = $this->get('/api/v1/billing-summaries/export.csv?year_month=2026-04');

        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('billing-summary-2026-04-deal.csv', $res->headers->get('Content-Disposition'));
        $body = $res->streamedContent();
        $this->assertStringContainsString('案件名', $body);
        $this->assertStringContainsString('A社', $body);
        $this->assertStringContainsString('885500', $body);
    }
}
