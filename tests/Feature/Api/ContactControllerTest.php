<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Tenant;
use Tests\TestCase;

class ContactControllerTest extends TestCase
{
    // ─── index ───

    public function test_index_returns_paginated_contacts(): void
    {
        $this->actingAsUser();

        Contact::factory()->count(3)->create();

        $res = $this->getJson('/api/v1/contacts');

        $res->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
        $this->assertCount(3, $res->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/contacts')->assertUnauthorized();
    }

    public function test_index_searches_by_name(): void
    {
        $this->skipIfSqlite(); // ilike は PostgreSQL 固有
        $this->actingAsUser();

        Contact::factory()->create(['name' => '田中 太郎']);
        Contact::factory()->create(['name' => '佐藤 花子']);

        $res = $this->getJson('/api/v1/contacts?search=' . urlencode('田中'));

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('田中 太郎', $res->json('data.0.name'));
    }

    public function test_index_filters_by_customer_id(): void
    {
        $this->actingAsUser();

        $c1 = Customer::factory()->create();
        $c2 = Customer::factory()->create();
        Contact::factory()->create(['customer_id' => $c1->id]);
        Contact::factory()->create(['customer_id' => $c2->id]);

        $res = $this->getJson("/api/v1/contacts?customer_id={$c1->id}");

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame($c1->id, $res->json('data.0.customer.id'));
    }

    public function test_index_only_returns_own_tenant_contacts(): void
    {
        $this->actingAsUser();

        Contact::factory()->create(['name' => '自テナント担当者']);

        $otherTenant = Tenant::factory()->create();
        $otherCustomer = (new Customer)->forceFill([
            'company_name' => '他テナント顧客',
            'tenant_id'    => $otherTenant->id,
        ]);
        $otherCustomer->save();
        (new Contact)->forceFill([
            'customer_id' => $otherCustomer->id,
            'name'        => '他テナント担当者',
            'tenant_id'   => $otherTenant->id,
        ])->save();

        $res = $this->getJson('/api/v1/contacts');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('自テナント担当者', $res->json('data.0.name'));
    }

    // ─── store ───

    public function test_store_creates_contact(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create();

        $res = $this->postJson('/api/v1/contacts', [
            'customer_id' => $customer->id,
            'name'        => '山田 太郎',
            'department'  => '営業部',
            'position'    => '部長',
            'email'       => 'yamada@example.com',
            'phone'       => '03-1234-5678',
        ]);

        $res->assertCreated()->assertJsonPath('data.name', '山田 太郎');
        $this->assertDatabaseHas('contacts', ['name' => '山田 太郎', 'email' => 'yamada@example.com']);
    }

    public function test_store_requires_name_and_customer(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/v1/contacts', []);

        $res->assertStatus(422)->assertJsonValidationErrors(['name', 'customer_id']);
    }

    public function test_store_rejects_invalid_email(): void
    {
        $this->actingAsUser();
        $customer = Customer::factory()->create();

        $res = $this->postJson('/api/v1/contacts', [
            'customer_id' => $customer->id,
            'name'        => '山田',
            'email'       => 'invalid-email',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    // ─── show ───

    public function test_show_returns_contact_detail(): void
    {
        $this->actingAsUser();
        $contact = Contact::factory()->create(['name' => '佐々木']);

        $res = $this->getJson("/api/v1/contacts/{$contact->id}");

        $res->assertOk()->assertJsonPath('data.name', '佐々木');
    }

    public function test_show_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant   = Tenant::factory()->create();
        $otherCustomer = (new Customer)->forceFill(['company_name' => 'X', 'tenant_id' => $otherTenant->id]);
        $otherCustomer->save();
        $otherContact = (new Contact)->forceFill([
            'customer_id' => $otherCustomer->id,
            'name'        => '他テナント担当者',
            'tenant_id'   => $otherTenant->id,
        ]);
        $otherContact->save();

        $this->getJson("/api/v1/contacts/{$otherContact->id}")->assertNotFound();
    }

    // ─── update ───

    public function test_update_modifies_contact(): void
    {
        $this->actingAsUser();
        $contact = Contact::factory()->create(['name' => '元の名前']);

        $res = $this->putJson("/api/v1/contacts/{$contact->id}", [
            'customer_id' => $contact->customer_id,
            'name'        => '更新後',
        ]);

        $res->assertOk()->assertJsonPath('data.name', '更新後');
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'name' => '更新後']);
    }

    public function test_update_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant   = Tenant::factory()->create();
        $otherCustomer = (new Customer)->forceFill(['company_name' => 'X', 'tenant_id' => $otherTenant->id]);
        $otherCustomer->save();
        $otherContact = (new Contact)->forceFill([
            'customer_id' => $otherCustomer->id,
            'name'        => '他テナント',
            'tenant_id'   => $otherTenant->id,
        ]);
        $otherContact->save();

        $res = $this->putJson("/api/v1/contacts/{$otherContact->id}", [
            'customer_id' => $otherCustomer->id,
            'name'        => '上書き試行',
        ]);

        $res->assertNotFound();
    }

    // ─── destroy ───

    public function test_destroy_soft_deletes_contact(): void
    {
        $this->actingAsUser();
        $contact = Contact::factory()->create();

        $res = $this->deleteJson("/api/v1/contacts/{$contact->id}");

        $res->assertNoContent();
        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
    }

    public function test_destroy_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant   = Tenant::factory()->create();
        $otherCustomer = (new Customer)->forceFill(['company_name' => 'X', 'tenant_id' => $otherTenant->id]);
        $otherCustomer->save();
        $otherContact = (new Contact)->forceFill([
            'customer_id' => $otherCustomer->id,
            'name'        => '他テナント',
            'tenant_id'   => $otherTenant->id,
        ]);
        $otherContact->save();

        $this->deleteJson("/api/v1/contacts/{$otherContact->id}")->assertNotFound();
        $this->assertDatabaseHas('contacts', ['id' => $otherContact->id, 'deleted_at' => null]);
    }
}
