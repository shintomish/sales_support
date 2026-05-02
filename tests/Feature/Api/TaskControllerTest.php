<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class TaskControllerTest extends TestCase
{
    // ─── index ───

    public function test_index_returns_paginated_tasks(): void
    {
        $this->actingAsUser();
        Task::factory()->count(3)->create(['user_id' => $this->authUser->id]);

        $res = $this->getJson('/api/v1/tasks');

        $res->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
        $this->assertCount(3, $res->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
    }

    public function test_index_only_returns_own_tenant_tasks(): void
    {
        $this->actingAsUser();
        Task::factory()->create(['title' => '自テナントタスク', 'user_id' => $this->authUser->id]);

        $otherTenant = Tenant::factory()->create();
        $otherUser   = User::factory()->tenantUser($otherTenant)->create();
        $otherCust   = (new Customer)->forceFill(['company_name' => 'X', 'tenant_id' => $otherTenant->id]);
        $otherCust->save();
        (new Task)->forceFill([
            'user_id'     => $otherUser->id,
            'customer_id' => $otherCust->id,
            'title'       => '他テナントタスク',
            'priority'    => '中',
            'status'      => '未着手',
            'tenant_id'   => $otherTenant->id,
        ])->save();

        $res = $this->getJson('/api/v1/tasks');

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('自テナントタスク', $titles);
        $this->assertNotContains('他テナントタスク', $titles);
    }

    // ─── store ───

    public function test_store_creates_task(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/v1/tasks', [
            'title'    => '提案資料作成',
            'priority' => '高',
            'status'   => '未着手',
            'due_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $res->assertCreated()->assertJsonPath('data.title', '提案資料作成');
        $this->assertDatabaseHas('tasks', ['title' => '提案資料作成']);
    }

    public function test_store_requires_title_priority_status(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/v1/tasks', []);

        $res->assertStatus(422)->assertJsonValidationErrors(['title', 'priority', 'status']);
    }

    public function test_store_rejects_invalid_priority(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/v1/tasks', [
            'title' => 'X', 'priority' => 'urgent', 'status' => '未着手',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['priority']);
    }

    // ─── show ───

    public function test_show_returns_task_detail(): void
    {
        $this->actingAsUser();
        $task = Task::factory()->create(['title' => '見積送付']);

        $res = $this->getJson("/api/v1/tasks/{$task->id}");

        $res->assertOk()->assertJsonPath('data.title', '見積送付');
    }

    public function test_show_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $otherUser   = User::factory()->tenantUser($otherTenant)->create();
        $otherTask   = (new Task)->forceFill([
            'user_id'   => $otherUser->id,
            'title'     => '他テナント',
            'priority'  => '中',
            'status'    => '未着手',
            'tenant_id' => $otherTenant->id,
        ]);
        $otherTask->save();

        $this->getJson("/api/v1/tasks/{$otherTask->id}")->assertNotFound();
    }

    // ─── update ───

    public function test_update_modifies_task(): void
    {
        $this->actingAsUser();
        $task = Task::factory()->create(['title' => '元タイトル', 'priority' => '低', 'status' => '未着手']);

        $res = $this->putJson("/api/v1/tasks/{$task->id}", [
            'title'    => '更新後',
            'priority' => '高',
            'status'   => '進行中',
        ]);

        $res->assertOk()->assertJsonPath('data.title', '更新後');
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'priority' => '高', 'status' => '進行中']);
    }

    public function test_update_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $otherUser   = User::factory()->tenantUser($otherTenant)->create();
        $otherTask   = (new Task)->forceFill([
            'user_id'   => $otherUser->id,
            'title'     => '他テナント',
            'priority'  => '中',
            'status'    => '未着手',
            'tenant_id' => $otherTenant->id,
        ]);
        $otherTask->save();

        $res = $this->putJson("/api/v1/tasks/{$otherTask->id}", [
            'title' => 'X', 'priority' => '中', 'status' => '完了',
        ]);

        $res->assertNotFound();
    }

    // ─── updateStatus ───

    public function test_update_status_changes_status_only(): void
    {
        $this->actingAsUser();
        $task = Task::factory()->create(['status' => '未着手']);

        $res = $this->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => '完了']);

        $res->assertOk();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => '完了']);
    }

    // ─── destroy ───

    public function test_destroy_soft_deletes_task(): void
    {
        $this->actingAsUser();
        $task = Task::factory()->create();

        $this->deleteJson("/api/v1/tasks/{$task->id}")->assertNoContent();

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_destroy_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $otherUser   = User::factory()->tenantUser($otherTenant)->create();
        $otherTask   = (new Task)->forceFill([
            'user_id'   => $otherUser->id,
            'title'     => '他テナント',
            'priority'  => '中',
            'status'    => '未着手',
            'tenant_id' => $otherTenant->id,
        ]);
        $otherTask->save();

        $this->deleteJson("/api/v1/tasks/{$otherTask->id}")->assertNotFound();
        $this->assertDatabaseHas('tasks', ['id' => $otherTask->id, 'deleted_at' => null]);
    }
}
