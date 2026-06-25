<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\SesContract;
use App\Models\Tenant;
use App\Models\WorkRecord;
use Tests\TestCase;

class InvoiceControllerTest extends TestCase
{
    private function setupSesDeal(array $contract = [], array $record = [], ?Customer $customer = null): array
    {
        $customer = $customer ?? Customer::factory()->create([
            'company_name' => 'A社',
            'invoice_code' => 'A001',
            'address'      => '東京都千代田区1-1',
        ]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'deal_type'   => 'ses',
            'title'       => '案件X',
        ]);
        SesContract::query()->create(array_merge([
            'tenant_id'                   => $deal->tenant_id,
            'deal_id'                     => $deal->id,
            'income_amount'               => 800000,
            'client_deduction_unit_price' => 5000,
            'client_deduction_hours'      => 140,
            'client_overtime_unit_price'  => 6250,
            'client_overtime_hours'       => 180,
            'engineer_name'               => '山田太郎',
            'payment_site'                => 30,
        ], $contract));
        WorkRecord::query()->create(array_merge([
            'tenant_id'          => $deal->tenant_id,
            'deal_id'            => $deal->id,
            'year_month'         => '2026-04',
            'actual_hours'       => 160,
            'transportation_fee' => 5000,
        ], $record));
        return ['customer' => $customer, 'deal' => $deal];
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/invoices')->assertUnauthorized();
    }

    public function test_store_creates_invoice_with_auto_lines(): void
    {
        $this->actingAsUser();
        ['deal' => $deal] = $this->setupSesDeal();

        $res = $this->postJson('/api/v1/invoices', [
            'deal_id'    => $deal->id,
            'year_month' => '2026-04',
        ]);

        $res->assertCreated();
        $this->assertSame('INV-A001-202604-001', $res->json('invoice_number'));
        $this->assertSame('draft', $res->json('status'));
        // 基本800000(10%) → 課税小計800000・税80000。
        // 交通費5000は経費(is_expense=非課税)のため小計には含めず合計に直接加算 → 合計885000
        $this->assertEquals(800000, $res->json('subtotal'));
        $this->assertEquals(80000,  $res->json('tax'));
        $this->assertEquals(885000, $res->json('total'));
        $this->assertCount(2, $res->json('lines'));
        // スナップショット
        $this->assertSame('A社', $res->json('customer_name_snapshot'));
        $this->assertSame('山田太郎', $res->json('engineer_name_snapshot'));
    }

    public function test_store_rejects_when_customer_invoice_code_missing(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['invoice_code' => null]);
        ['deal' => $deal] = $this->setupSesDeal([], [], $customer);

        // RuntimeException がそのまま 500 で返る
        $res = $this->postJson('/api/v1/invoices', [
            'deal_id'    => $deal->id,
            'year_month' => '2026-04',
        ]);
        $res->assertStatus(500);
    }

    public function test_store_rejects_duplicate_for_same_deal_and_month(): void
    {
        $this->actingAsUser();
        ['deal' => $deal] = $this->setupSesDeal();
        $this->postJson('/api/v1/invoices', ['deal_id' => $deal->id, 'year_month' => '2026-04'])->assertCreated();

        $res = $this->postJson('/api/v1/invoices', ['deal_id' => $deal->id, 'year_month' => '2026-04']);
        $res->assertStatus(422);
    }

    public function test_invoice_number_increments_per_customer_and_month(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['company_name' => 'A社', 'invoice_code' => 'A001']);
        $d1 = Deal::factory()->create(['customer_id' => $customer->id, 'deal_type' => 'ses']);
        $d2 = Deal::factory()->create(['customer_id' => $customer->id, 'deal_type' => 'ses']);
        SesContract::query()->create(['tenant_id' => $d1->tenant_id, 'deal_id' => $d1->id, 'income_amount' => 100000]);
        SesContract::query()->create(['tenant_id' => $d2->tenant_id, 'deal_id' => $d2->id, 'income_amount' => 200000]);
        WorkRecord::query()->create(['tenant_id' => $d1->tenant_id, 'deal_id' => $d1->id, 'year_month' => '2026-04']);
        WorkRecord::query()->create(['tenant_id' => $d2->tenant_id, 'deal_id' => $d2->id, 'year_month' => '2026-04']);

        $res1 = $this->postJson('/api/v1/invoices', ['deal_id' => $d1->id, 'year_month' => '2026-04']);
        $res2 = $this->postJson('/api/v1/invoices', ['deal_id' => $d2->id, 'year_month' => '2026-04']);

        $this->assertSame('INV-A001-202604-001', $res1->json('invoice_number'));
        $this->assertSame('INV-A001-202604-002', $res2->json('invoice_number'));
    }

    /**
     * 支払期限 = 翌月末 + (payment_site - 30)日。土日祝は翌営業日に振替。
     *   2026-04 / 50d → 5/31 + 20 = 6/20(土) → 6/22(月)
     *   2026-05 / 50d → 6/30 + 20 = 7/20(月) は海の日 → 7/21(火)
     *   2026-04 / 30d → 5/31(日) → 6/1(月)
     */
    public function test_due_date_uses_end_of_billing_month_plus_payment_site(): void
    {
        $this->actingAsUser();

        $cases = [
            ['payment_site' => 50, 'year_month' => '2026-04', 'expected' => '2026-06-22'],
            ['payment_site' => 50, 'year_month' => '2026-05', 'expected' => '2026-07-21'],
            ['payment_site' => 30, 'year_month' => '2026-04', 'expected' => '2026-06-01'],
        ];

        foreach ($cases as $i => $c) {
            $customer = Customer::factory()->create([
                'company_name' => "C{$i}",
                'invoice_code' => "C{$i}",
            ]);
            ['deal' => $deal] = $this->setupSesDeal(
                ['payment_site' => $c['payment_site']],
                ['year_month'   => $c['year_month']],
                $customer
            );

            $res = $this->postJson('/api/v1/invoices', [
                'deal_id'    => $deal->id,
                'year_month' => $c['year_month'],
            ]);

            $res->assertCreated();
            $this->assertSame(
                $c['expected'],
                $res->json('due_date'),
                sprintf('payment_site=%d / year_month=%s', $c['payment_site'], $c['year_month'])
            );
        }
    }

    public function test_invoice_number_resets_per_month(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create(['invoice_code' => 'A001']);
        $d = Deal::factory()->create(['customer_id' => $customer->id, 'deal_type' => 'ses']);
        SesContract::query()->create(['tenant_id' => $d->tenant_id, 'deal_id' => $d->id, 'income_amount' => 100000]);
        WorkRecord::query()->create(['tenant_id' => $d->tenant_id, 'deal_id' => $d->id, 'year_month' => '2026-04']);
        WorkRecord::query()->create(['tenant_id' => $d->tenant_id, 'deal_id' => $d->id, 'year_month' => '2026-05']);

        $r1 = $this->postJson('/api/v1/invoices', ['deal_id' => $d->id, 'year_month' => '2026-04']);
        $r2 = $this->postJson('/api/v1/invoices', ['deal_id' => $d->id, 'year_month' => '2026-05']);

        $this->assertSame('INV-A001-202604-001', $r1->json('invoice_number'));
        $this->assertSame('INV-A001-202605-001', $r2->json('invoice_number'));
    }

    public function test_update_recalculates_totals_with_mixed_tax_rates(): void
    {
        $this->actingAsUser();
        ['deal' => $deal] = $this->setupSesDeal();
        $created = $this->postJson('/api/v1/invoices', ['deal_id' => $deal->id, 'year_month' => '2026-04'])->json();

        $payload = [
            'lines' => [
                ['description' => 'システム開発', 'quantity' => 1, 'unit' => '式', 'unit_price' => 500000, 'tax_rate' => 0.10],
                ['description' => '会議用茶菓',   'quantity' => 1, 'unit' => '式', 'unit_price' => 10000,  'tax_rate' => 0.08],
            ],
        ];
        $res = $this->putJson('/api/v1/invoices/' . $created['id'], $payload);

        $res->assertOk();
        // 500000*10% = 50000、10000*8% = 800 → 計 50800
        $this->assertEquals(510000, $res->json('subtotal'));
        $this->assertEquals(50800,  $res->json('tax'));
        $this->assertEquals(560800, $res->json('total'));
    }

    public function test_destroy_allows_issued_invoice_for_recovery(): void
    {
        $this->actingAsUser();
        ['deal' => $deal] = $this->setupSesDeal();
        $created = $this->postJson('/api/v1/invoices', ['deal_id' => $deal->id, 'year_month' => '2026-04'])->json();

        // 誤発行のリカバリ用に issued でも削除可
        $this->putJson('/api/v1/invoices/' . $created['id'], ['status' => 'issued'])->assertOk();
        $this->deleteJson('/api/v1/invoices/' . $created['id'])->assertNoContent();
        $this->assertNull(Invoice::find($created['id']));
    }

    public function test_destroy_allows_draft(): void
    {
        $this->actingAsUser();
        ['deal' => $deal] = $this->setupSesDeal();
        $created = $this->postJson('/api/v1/invoices', ['deal_id' => $deal->id, 'year_month' => '2026-04'])->json();

        $this->deleteJson('/api/v1/invoices/' . $created['id'])->assertNoContent();
        $this->assertNull(Invoice::find($created['id']));
    }

    public function test_does_not_show_other_tenant_invoices(): void
    {
        $this->actingAsUser();
        ['deal' => $deal] = $this->setupSesDeal();
        $this->postJson('/api/v1/invoices', ['deal_id' => $deal->id, 'year_month' => '2026-04'])->assertCreated();

        // 他テナントの請求書（GlobalScope を抜けて作成）
        $otherUser = $this->createUserInAnotherTenant();
        $otherCustomer = (new Customer)->forceFill([
            'company_name' => 'B社', 'invoice_code' => 'B001', 'tenant_id' => $otherUser->tenant_id,
        ]);
        $otherCustomer->save();
        $otherDeal = (new Deal)->forceFill([
            'customer_id' => $otherCustomer->id,
            'user_id'     => $otherUser->id,
            'tenant_id'   => $otherUser->tenant_id,
            'title'       => '他案件',
            'deal_type'   => 'ses',
            'status'      => '新規',
        ]);
        $otherDeal->save();
        (new Invoice)->forceFill([
            'tenant_id'      => $otherUser->tenant_id,
            'deal_id'        => $otherDeal->id,
            'customer_id'    => $otherCustomer->id,
            'year_month'     => '2026-04',
            'invoice_number' => 'INV-B001-202604-001',
            'issued_date'    => '2026-04-30',
            'status'         => 'draft',
        ])->save();

        $list = $this->getJson('/api/v1/invoices')->json('data');
        $this->assertCount(1, $list);
    }
}
