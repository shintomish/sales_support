<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Tenant;
use Tests\TestCase;

class DealControllerTest extends TestCase
{
    // ───────────────────────────────────────────
    // index
    // ───────────────────────────────────────────

    public function test_index_returns_paginated_deals(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        Deal::factory()->count(3)->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'deal_type' => 'general']);

        $response = $this->getJson('/api/v1/deals');

        $response->assertOk()
                 ->assertJsonStructure(['data', 'links', 'meta']);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/deals');

        $response->assertUnauthorized();
    }

    public function test_index_excludes_ses_deals(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'deal_type' => 'general', 'title' => '通常案件']);
        Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'deal_type' => 'ses',     'title' => 'SES案件']);

        $response = $this->getJson('/api/v1/deals');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('通常案件', $response->json('data.0.title'));
    }

    public function test_index_filters_by_status(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'status' => '新規']);
        Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'status' => '成約']);

        $response = $this->getJson('/api/v1/deals?status=新規');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('新規', $response->json('data.0.status'));
    }

    public function test_index_filters_by_customer_id(): void
    {
        $this->actingAsUser();

        $customer1 = Customer::factory()->create();
        $customer2 = Customer::factory()->create();
        Deal::factory()->create(['customer_id' => $customer1->id, 'user_id' => $this->authUser->id]);
        Deal::factory()->create(['customer_id' => $customer2->id, 'user_id' => $this->authUser->id]);

        $response = $this->getJson("/api/v1/deals?customer_id={$customer1->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_filters_by_amount_range(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'amount' => 100000]);
        Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'amount' => 500000]);
        Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'amount' => 1000000]);

        $response = $this->getJson('/api/v1/deals?amount_min=200000&amount_max=600000');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_only_returns_own_tenant_deals(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'title' => '自テナント案件']);

        $otherTenant = Tenant::factory()->create();
        $otherCustomer = (new Customer)->forceFill(['company_name' => '他社', 'tenant_id' => $otherTenant->id]);
        $otherCustomer->save();
        $otherUser = \App\Models\User::factory()->create();
        (new Deal)->forceFill([
            'customer_id' => $otherCustomer->id,
            'user_id'     => $otherUser->id,
            'title'       => '他テナント案件',
            'status'      => '新規',
            'deal_type'   => 'general',
            'tenant_id'   => $otherTenant->id,
        ])->save();

        $response = $this->getJson('/api/v1/deals');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('自テナント案件', $response->json('data.0.title'));
    }

    // ───────────────────────────────────────────
    // store
    // ───────────────────────────────────────────

    public function test_store_creates_deal(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();

        $response = $this->postJson('/api/v1/deals', [
            'customer_id'         => $customer->id,
            'title'               => '新規SES案件テスト',
            'status'              => '新規',
            'amount'              => 500000,
            'probability'         => 70,
            'expected_close_date' => '2026-06-30',
        ]);

        $response->assertCreated()
                 ->assertJsonPath('data.title', '新規SES案件テスト')
                 ->assertJsonPath('data.status', '新規');

        $this->assertDatabaseHas('deals', [
            'title'     => '新規SES案件テスト',
            'deal_type' => 'general',
        ]);
    }

    public function test_store_requires_customer_id(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/deals', [
            'title'  => 'タイトルのみ',
            'status' => '新規',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_store_requires_title(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();

        $response = $this->postJson('/api/v1/deals', [
            'customer_id' => $customer->id,
            'status'      => '新規',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['title']);
    }

    public function test_store_validates_status_enum(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();

        $response = $this->postJson('/api/v1/deals', [
            'customer_id' => $customer->id,
            'title'       => 'テスト案件',
            'status'      => '無効なステータス',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['status']);
    }

    public function test_store_validates_probability_range(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();

        $response = $this->postJson('/api/v1/deals', [
            'customer_id' => $customer->id,
            'title'       => 'テスト案件',
            'status'      => '新規',
            'probability' => 150,
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['probability']);
    }

    public function test_store_validates_actual_close_date_after_expected(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();

        $response = $this->postJson('/api/v1/deals', [
            'customer_id'         => $customer->id,
            'title'               => 'テスト案件',
            'status'              => '新規',
            'expected_close_date' => '2026-06-30',
            'actual_close_date'   => '2026-05-01', // 予定より前
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['actual_close_date']);
    }

    // ───────────────────────────────────────────
    // show
    // ───────────────────────────────────────────

    public function test_show_returns_deal_detail(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        $deal     = Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'title' => '詳細テスト案件']);

        $response = $this->getJson("/api/v1/deals/{$deal->id}");

        $response->assertOk()
                 ->assertJsonPath('data.title', '詳細テスト案件')
                 ->assertJsonStructure(['data' => ['id', 'title', 'status', 'customer', 'activities']]);
    }

    public function test_show_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant   = Tenant::factory()->create();
        $otherCustomer = (new Customer)->forceFill(['company_name' => '他社', 'tenant_id' => $otherTenant->id]);
        $otherCustomer->save();
        $otherUser = \App\Models\User::factory()->create();
        $otherDeal = (new Deal)->forceFill([
            'customer_id' => $otherCustomer->id,
            'user_id'     => $otherUser->id,
            'title'       => '他テナント案件',
            'status'      => '新規',
            'deal_type'   => 'general',
            'tenant_id'   => $otherTenant->id,
        ]);
        $otherDeal->save();

        $response = $this->getJson("/api/v1/deals/{$otherDeal->id}");

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // update
    // ───────────────────────────────────────────

    public function test_update_modifies_deal(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        $deal     = Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id, 'status' => '新規']);

        $response = $this->putJson("/api/v1/deals/{$deal->id}", [
            'customer_id' => $customer->id,
            'title'       => '更新後タイトル',
            'status'      => '提案',
        ]);

        $response->assertOk()
                 ->assertJsonPath('data.title', '更新後タイトル')
                 ->assertJsonPath('data.status', '提案');

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'status' => '提案']);
    }

    public function test_update_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant   = Tenant::factory()->create();
        $otherCustomer = (new Customer)->forceFill(['company_name' => '他社', 'tenant_id' => $otherTenant->id]);
        $otherCustomer->save();
        $otherUser = \App\Models\User::factory()->create();
        $otherDeal = (new Deal)->forceFill([
            'customer_id' => $otherCustomer->id,
            'user_id'     => $otherUser->id,
            'title'       => '他テナント案件',
            'status'      => '新規',
            'deal_type'   => 'general',
            'tenant_id'   => $otherTenant->id,
        ]);
        $otherDeal->save();

        $response = $this->putJson("/api/v1/deals/{$otherDeal->id}", [
            'customer_id' => $otherCustomer->id,
            'title'       => '変更しようとする',
            'status'      => '提案',
        ]);

        $response->assertNotFound();
    }

    // ───────────────────────────────────────────
    // destroy
    // ───────────────────────────────────────────

    public function test_destroy_soft_deletes_deal(): void
    {
        $this->actingAsUser();

        $customer = Customer::factory()->create();
        $deal     = Deal::factory()->create(['customer_id' => $customer->id, 'user_id' => $this->authUser->id]);

        $response = $this->deleteJson("/api/v1/deals/{$deal->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('deals', ['id' => $deal->id]);
    }

    public function test_destroy_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant   = Tenant::factory()->create();
        $otherCustomer = (new Customer)->forceFill(['company_name' => '他社', 'tenant_id' => $otherTenant->id]);
        $otherCustomer->save();
        $otherUser = \App\Models\User::factory()->create();
        $otherDeal = (new Deal)->forceFill([
            'customer_id' => $otherCustomer->id,
            'user_id'     => $otherUser->id,
            'title'       => '他テナント案件',
            'status'      => '新規',
            'deal_type'   => 'general',
            'tenant_id'   => $otherTenant->id,
        ]);
        $otherDeal->save();

        $response = $this->deleteJson("/api/v1/deals/{$otherDeal->id}");

        $response->assertNotFound();
    }
}
