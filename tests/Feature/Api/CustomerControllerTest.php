<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    // ───────────────────────────────────────────
    // index
    // ───────────────────────────────────────────

    public function test_index_returns_paginated_customers(): void
    {
        $this->actingAsUser();

        Customer::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk()
                 ->assertJsonStructure(['data', 'links', 'meta']);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/customers');

        $response->assertUnauthorized();
    }

    public function test_index_searches_by_company_name(): void
    {
        $this->actingAsUser();

        Customer::factory()->create(['company_name' => '株式会社ターゲット']);
        Customer::factory()->create(['company_name' => '別の会社']);

        $response = $this->getJson('/api/v1/customers?search=ターゲット');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('株式会社ターゲット', $response->json('data.0.company_name'));
    }

    public function test_index_filters_by_industry(): void
    {
        $this->actingAsUser();

        Customer::factory()->create(['company_name' => 'IT企業A', 'industry' => 'IT']);
        Customer::factory()->create(['company_name' => '製造業B', 'industry' => '製造']);

        $response = $this->getJson('/api/v1/customers?industry=IT');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_only_returns_own_tenant_customers(): void
    {
        $this->actingAsUser();

        Customer::factory()->create(['company_name' => '自テナント顧客']);

        // 別テナントの顧客を forceFill で作成（fillable 制限と creating イベントをバイパス）
        $otherTenant = Tenant::factory()->create();
        (new Customer)->forceFill([
            'company_name' => '他テナント顧客',
            'tenant_id'    => $otherTenant->id,
        ])->save();

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('自テナント顧客', $response->json('data.0.company_name'));
    }

    // ───────────────────────────────────────────
    // store
    // ───────────────────────────────────────────

    public function test_store_creates_customer(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/customers', [
            'company_name'   => '株式会社テスト',
            'industry'       => 'IT',
            'phone'          => '03-1234-5678',
            'address'        => '東京都千代田区1-1-1',
            'employee_count' => 100,
            'website'        => 'https://example.com',
            'notes'          => '備考テキスト',
        ]);

        $response->assertCreated()
                 ->assertJsonPath('data.company_name', '株式会社テスト');

        $this->assertDatabaseHas('customers', ['company_name' => '株式会社テスト']);
    }

    public function test_store_requires_company_name(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/customers', []);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['company_name']);
    }

    public function test_store_rejects_duplicate_company_name(): void
    {
        $this->actingAsUser();

        Customer::factory()->create(['company_name' => '既存の会社']);

        $response = $this->postJson('/api/v1/customers', [
            'company_name' => '既存の会社',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['company_name']);
    }

    public function test_store_rejects_invalid_phone_format(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/customers', [
            'company_name' => '株式会社電話テスト',
            'phone'        => 'invalid-phone',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['phone']);
    }

    public function test_store_rejects_invalid_website_url(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/customers', [
            'company_name' => '株式会社URLテスト',
            'website'      => 'not-a-url',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['website']);
    }

    // ───────────────────────────────────────────
    // show
    // ───────────────────────────────────────────

    public function test_show_returns_customer_detail(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create(['company_name' => '詳細テスト株式会社']);

        $response = $this->getJson("/api/v1/customers/{$customer->id}");

        $response->assertOk()
                 ->assertJsonPath('data.company_name', '詳細テスト株式会社')
                 ->assertJsonStructure(['data' => ['id', 'company_name', 'contacts', 'deals']]);
    }

    public function test_show_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $customer    = (new Customer)->forceFill([
            'company_name' => '他テナント顧客',
            'tenant_id'    => $otherTenant->id,
        ]);
        $customer->save();

        $response = $this->getJson("/api/v1/customers/{$customer->id}");

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // update
    // ───────────────────────────────────────────

    public function test_update_modifies_customer(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create(['company_name' => '旧会社名']);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'company_name' => '新会社名',
            'industry'     => 'IT',
        ]);

        $response->assertOk()
                 ->assertJsonPath('data.company_name', '新会社名');

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'company_name' => '新会社名']);
    }

    public function test_update_allows_same_company_name_for_self(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create(['company_name' => '同名テスト']);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'company_name' => '同名テスト',
        ]);

        $response->assertOk();
    }

    // ───────────────────────────────────────────
    // destroy
    // ───────────────────────────────────────────

    public function test_destroy_soft_deletes_customer(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_destroy_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $customer    = (new Customer)->forceFill([
            'company_name' => '他テナント顧客',
            'tenant_id'    => $otherTenant->id,
        ]);
        $customer->save();

        $response = $this->deleteJson("/api/v1/customers/{$customer->id}");

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // industries
    // ───────────────────────────────────────────

    public function test_industries_returns_distinct_list(): void
    {
        $this->actingAsUser();

        Customer::factory()->create(['industry' => 'IT']);
        Customer::factory()->create(['industry' => 'IT']);
        Customer::factory()->create(['industry' => '製造']);

        $response = $this->getJson('/api/v1/customers/industries');

        $response->assertOk();
        $industries = $response->json();
        $this->assertContains('IT', $industries);
        $this->assertContains('製造', $industries);
        $this->assertCount(count(array_unique($industries)), $industries); // 重複なし
    }
}
