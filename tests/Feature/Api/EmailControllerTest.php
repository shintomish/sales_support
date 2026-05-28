<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Email;
use App\Models\EngineerMailSource;
use App\Models\GmailToken;
use App\Models\ProjectMailSource;
use App\Models\Tenant;
use App\Services\GmailService;
use Mockery\MockInterface;
use Tests\TestCase;

class EmailControllerTest extends TestCase
{
    // ─── index ───

    public function test_index_returns_paginated_emails(): void
    {
        $this->actingAsUser();
        Email::factory()->count(3)->create(['tenant_id' => $this->authUser->tenant_id]);

        $res = $this->getJson('/api/v1/emails');

        $res->assertOk()->assertJsonStructure(['data', 'current_page', 'total']);
        $this->assertCount(3, $res->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/emails')->assertUnauthorized();
    }

    public function test_index_filters_by_unread(): void
    {
        $this->actingAsUser();
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'is_read' => true,  'subject' => '既読']);
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'is_read' => false, 'subject' => '未読']);

        $res = $this->getJson('/api/v1/emails?unread=1');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('未読', $res->json('data.0.subject'));
    }

    // ilike 検索は PostgreSQL 固有のため tests/Pgsql/Feature/EmailSearchTest.php に移動

    public function test_index_filters_by_category(): void
    {
        $this->actingAsUser();
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'category' => 'engineer', 'subject' => '技術者']);
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'category' => 'project',  'subject' => '案件']);

        $res = $this->getJson('/api/v1/emails?category=project');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('案件', $res->json('data.0.subject'));
    }

    public function test_index_only_returns_own_tenant_emails(): void
    {
        $this->actingAsUser();
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'subject' => '自テナント']);

        $other = Tenant::factory()->create();
        Email::factory()->create(['tenant_id' => $other->id, 'subject' => '他テナント']);

        $res = $this->getJson('/api/v1/emails');

        $res->assertOk();
        $subjects = collect($res->json('data'))->pluck('subject')->all();
        $this->assertContains('自テナント', $subjects);
        $this->assertNotContains('他テナント', $subjects);
    }

    // ─── show ───

    public function test_show_returns_email_detail(): void
    {
        $this->actingAsUser();
        $email = Email::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'subject'   => '詳細テスト',
            'is_read'   => true,
        ]);

        $res = $this->getJson("/api/v1/emails/{$email->id}");

        $res->assertOk()->assertJsonPath('subject', '詳細テスト');
    }

    public function test_show_marks_email_as_read(): void
    {
        $this->actingAsUser();
        $email = Email::factory()->create([
            'tenant_id'        => $this->authUser->tenant_id,
            'gmail_message_id' => 'imap-123',
            'is_read'          => false,
        ]);

        $this->getJson("/api/v1/emails/{$email->id}")->assertOk();

        $this->assertTrue($email->fresh()->is_read);
    }

    public function test_show_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();
        $other = Tenant::factory()->create();
        $email = Email::factory()->create(['tenant_id' => $other->id]);

        $this->getJson("/api/v1/emails/{$email->id}")->assertNotFound();
    }

    // ─── destroy ───

    public function test_destroy_removes_email_and_related_records(): void
    {
        $this->actingAsUser();
        $email = Email::factory()->create(['tenant_id' => $this->authUser->tenant_id]);

        // 関連レコード
        ProjectMailSource::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'email_id'  => $email->id,
        ]);
        EngineerMailSource::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'email_id'  => $email->id,
        ]);

        $res = $this->deleteJson("/api/v1/emails/{$email->id}");

        $res->assertNoContent();
        $this->assertDatabaseMissing('emails', ['id' => $email->id]);
        $this->assertDatabaseMissing('project_mail_sources', ['email_id' => $email->id, 'deleted_at' => null]);
        $this->assertDatabaseMissing('engineer_mail_sources', ['email_id' => $email->id, 'deleted_at' => null]);
    }

    public function test_destroy_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();
        $other = Tenant::factory()->create();
        $email = Email::factory()->create(['tenant_id' => $other->id]);

        $this->deleteJson("/api/v1/emails/{$email->id}")->assertNotFound();
    }

    // ─── link ───

    public function test_link_associates_email_with_contact_and_deal(): void
    {
        $this->actingAsUser();
        $email    = Email::factory()->create(['tenant_id' => $this->authUser->tenant_id]);
        $customer = Customer::factory()->create();
        $contact  = Contact::factory()->create(['customer_id' => $customer->id]);
        $deal     = Deal::factory()->create(['customer_id' => $customer->id]);

        $res = $this->patchJson("/api/v1/emails/{$email->id}/link", [
            'contact_id'  => $contact->id,
            'deal_id'     => $deal->id,
            'customer_id' => $customer->id,
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('emails', [
            'id'          => $email->id,
            'contact_id'  => $contact->id,
            'deal_id'     => $deal->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_link_validates_referenced_ids(): void
    {
        $this->actingAsUser();
        $email = Email::factory()->create(['tenant_id' => $this->authUser->tenant_id]);

        $res = $this->patchJson("/api/v1/emails/{$email->id}/link", [
            'contact_id' => 99999,
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['contact_id']);
    }

    // ─── unreadCount ───

    public function test_unread_count_returns_number_of_unread_emails(): void
    {
        $this->actingAsUser();
        Email::factory()->count(2)->create(['tenant_id' => $this->authUser->tenant_id, 'is_read' => false]);
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'is_read' => true]);

        $res = $this->getJson('/api/v1/emails/unread-count');

        $res->assertOk()->assertJson(['count' => 2]);
    }

    // ─── markAllRead (非同期ジョブ化版) ───

    public function test_mark_all_read_creates_pending_job_and_runner_processes_unread(): void
    {
        $this->actingAsUser();
        Email::factory()->count(3)->create(['tenant_id' => $this->authUser->tenant_id, 'is_read' => false]);

        $res = $this->postJson('/api/v1/emails/mark-all-read');

        $res->assertStatus(202)->assertJsonPath('job.total_count', 3);

        // job が作成された段階では未読は更新されていない
        $this->assertSame(3, Email::where('tenant_id', $this->authUser->tenant_id)->where('is_read', false)->count());

        // Schedule tick 相当を直接呼び出して既読化を実行
        app(\App\Services\RescoreJobRunner::class)->tick();

        $this->assertSame(0, Email::where('tenant_id', $this->authUser->tenant_id)->where('is_read', false)->count());
    }

    public function test_mark_all_read_does_not_affect_other_tenants(): void
    {
        $this->actingAsUser();
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'is_read' => false]);

        $other = Tenant::factory()->create();
        $otherEmail = Email::factory()->create(['tenant_id' => $other->id, 'is_read' => false]);

        $this->postJson('/api/v1/emails/mark-all-read')->assertStatus(202);
        app(\App\Services\RescoreJobRunner::class)->tick();

        $this->assertFalse($otherEmail->fresh()->is_read);
    }

    public function test_mark_all_read_returns_ok_when_no_unread(): void
    {
        $this->actingAsUser();
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'is_read' => true]);

        $res = $this->postJson('/api/v1/emails/mark-all-read');

        $res->assertOk()->assertJson(['count' => 0, 'job' => null]);
    }

    public function test_mark_all_read_returns_existing_job_when_already_running(): void
    {
        $this->actingAsUser();
        Email::factory()->create(['tenant_id' => $this->authUser->tenant_id, 'is_read' => false]);

        $first = $this->postJson('/api/v1/emails/mark-all-read')->assertStatus(202)->json('job.id');
        $second = $this->postJson('/api/v1/emails/mark-all-read')->assertStatus(202)->json('job.id');

        $this->assertSame($first, $second);
    }

    // ─── sync ───

    public function test_sync_returns_422_when_no_gmail_token(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/v1/emails/sync');

        $res->assertStatus(422)->assertJsonPath('message', 'Gmail未接続です');
    }

    public function test_sync_calls_gmail_service_and_returns_count(): void
    {
        $this->actingAsUser();

        GmailToken::create([
            'user_id'          => $this->authUser->id,
            'tenant_id'        => $this->authUser->tenant_id,
            'gmail_address'    => 'test@example.com',
            'access_token'     => 'tok',
            'refresh_token'    => 'rtok',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->mock(GmailService::class, function (MockInterface $mock) {
            $mock->shouldReceive('fetchAndStoreEmails')->once()->andReturn(5);
        });

        $res = $this->postJson('/api/v1/emails/sync');

        $res->assertOk()->assertJson(['count' => 5]);
    }
}
