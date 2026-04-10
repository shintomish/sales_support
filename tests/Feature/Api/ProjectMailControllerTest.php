<?php

namespace Tests\Feature\Api;

use App\Mail\ProposalMail;
use App\Models\Email;
use App\Models\Engineer;
use App\Models\EngineerProfile;
use App\Models\MailSendHistory;
use App\Models\ProjectMailSource;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProjectMailControllerTest extends TestCase
{
    // ───────────────────────────────────────────
    // index
    // ───────────────────────────────────────────

    public function test_index_returns_paginated_list(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        ProjectMailSource::factory()->count(3)->create([
            'email_id' => $email->id,
            'status'   => 'new',
        ]);

        $response = $this->getJson('/api/v1/project-mails');

        $response->assertOk()
                 ->assertJsonStructure(['data', 'total', 'per_page']);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/project-mails');

        $response->assertUnauthorized();
    }

    public function test_index_filters_by_status(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        ProjectMailSource::factory()->create(['email_id' => $email->id, 'status' => 'new']);
        ProjectMailSource::factory()->create(['email_id' => $email->id, 'status' => 'proposed']);

        $response = $this->getJson('/api/v1/project-mails?status=proposed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('proposed', $response->json('data.0.status'));
    }

    // ───────────────────────────────────────────
    // show
    // ───────────────────────────────────────────

    public function test_show_returns_detail(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $pms   = ProjectMailSource::factory()->create([
            'email_id' => $email->id,
            'title'    => 'テスト案件タイトル',
        ]);

        $response = $this->getJson("/api/v1/project-mails/{$pms->id}");

        $response->assertOk()
                 ->assertJsonPath('title', 'テスト案件タイトル');
    }

    // ───────────────────────────────────────────
    // updateStatus
    // ───────────────────────────────────────────

    public function test_update_status_changes_status(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $pms   = ProjectMailSource::factory()->create([
            'email_id' => $email->id,
            'status'   => 'new',
        ]);

        $response = $this->patchJson("/api/v1/project-mails/{$pms->id}/status", [
            'status' => 'proposed',
        ]);

        $response->assertOk()
                 ->assertJsonPath('status', 'proposed');
    }

    public function test_update_status_rejects_invalid_value(): void
    {
        $this->actingAsUser();

        $email = Email::factory()->create();
        $pms   = ProjectMailSource::factory()->create(['email_id' => $email->id]);

        $response = $this->patchJson("/api/v1/project-mails/{$pms->id}/status", [
            'status' => 'invalid_status',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['status']);
    }

    // ───────────────────────────────────────────
    // sendProposal（メール送信）
    // ───────────────────────────────────────────

    public function test_send_proposal_sends_mail_and_records_history(): void
    {
        Mail::fake();

        $this->actingAsUser();

        $email = Email::factory()->create();
        $pms   = ProjectMailSource::factory()->create(['email_id' => $email->id]);

        $response = $this->postJson("/api/v1/project-mails/{$pms->id}/send-proposal", [
            'to'      => 'recipient@example.com',
            'subject' => '技術者提案の件',
            'body'    => '以下の技術者をご提案します。',
        ]);

        $response->assertOk()
                 ->assertJsonPath('message', '送信しました');

        // ProposalMailが送信されている
        Mail::assertSent(ProposalMail::class, function ($mail) {
            return $mail->hasTo('recipient@example.com')
                && $mail->mailSubject === '技術者提案の件';
        });

        // 送信履歴が記録されている
        $this->assertDatabaseHas('mail_send_histories', [
            'project_mail_id' => $pms->id,
            'send_type'       => 'proposal',
            'to_address'      => 'recipient@example.com',
            'subject'         => '技術者提案の件',
            'status'          => 'sent',
        ]);
    }

    public function test_send_proposal_requires_to_address(): void
    {
        Mail::fake();

        $this->actingAsUser();

        $email = Email::factory()->create();
        $pms   = ProjectMailSource::factory()->create(['email_id' => $email->id]);

        $response = $this->postJson("/api/v1/project-mails/{$pms->id}/send-proposal", [
            'subject' => '件名',
            'body'    => '本文',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['to']);

        Mail::assertNothingSent();
    }

    public function test_send_proposal_rejects_invalid_email(): void
    {
        Mail::fake();

        $this->actingAsUser();

        $email = Email::factory()->create();
        $pms   = ProjectMailSource::factory()->create(['email_id' => $email->id]);

        $response = $this->postJson("/api/v1/project-mails/{$pms->id}/send-proposal", [
            'to'      => 'not-an-email',
            'subject' => '件名',
            'body'    => '本文',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['to']);

        Mail::assertNothingSent();
    }

    public function test_send_proposal_returns_404_for_other_tenant(): void
    {
        Mail::fake();

        $this->actingAsUser();

        // 他テナントのPMS
        $otherEmail = Email::factory()->create();
        $otherPms   = (new ProjectMailSource)->forceFill([
            'tenant_id' => \App\Models\Tenant::factory()->create()->id,
            'email_id'  => $otherEmail->id,
            'score'     => 50,
            'status'    => 'new',
            'received_at' => now(),
        ]);
        $otherPms->save();

        $response = $this->postJson("/api/v1/project-mails/{$otherPms->id}/send-proposal", [
            'to'      => 'recipient@example.com',
            'subject' => '件名',
            'body'    => '本文',
        ]);

        $response->assertNotFound();
        Mail::assertNothingSent();
    }

    // ───────────────────────────────────────────
    // sendBulk（一斉配信）
    // ───────────────────────────────────────────

    public function test_send_bulk_sends_to_multiple_recipients(): void
    {
        Mail::fake();

        $this->actingAsUser();

        $email = Email::factory()->create();
        $pms   = ProjectMailSource::factory()->create(['email_id' => $email->id]);

        $response = $this->postJson("/api/v1/project-mails/{$pms->id}/send-bulk", [
            'recipients' => [
                ['to' => 'a@example.com', 'name' => '担当A'],
                ['to' => 'b@example.com', 'name' => '担当B'],
                ['to' => 'c@example.com', 'name' => '担当C'],
            ],
            'subject' => '一斉配信テスト',
            'body'    => '本文です。',
        ]);

        $response->assertOk()
                 ->assertJsonPath('sent', 3)
                 ->assertJsonPath('failed', []);

        Mail::assertSentCount(3);

        // 送信履歴が3件記録されている
        $this->assertDatabaseCount('mail_send_histories', 3);
    }

    public function test_send_bulk_requires_at_least_one_recipient(): void
    {
        Mail::fake();

        $this->actingAsUser();

        $email = Email::factory()->create();
        $pms   = ProjectMailSource::factory()->create(['email_id' => $email->id]);

        $response = $this->postJson("/api/v1/project-mails/{$pms->id}/send-bulk", [
            'recipients' => [],
            'subject'    => '件名',
            'body'       => '本文',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['recipients']);

        Mail::assertNothingSent();
    }

    public function test_send_bulk_rejects_invalid_recipient_email(): void
    {
        Mail::fake();

        $this->actingAsUser();

        $email = Email::factory()->create();
        $pms   = ProjectMailSource::factory()->create(['email_id' => $email->id]);

        $response = $this->postJson("/api/v1/project-mails/{$pms->id}/send-bulk", [
            'recipients' => [
                ['to' => 'not-an-email'],
            ],
            'subject' => '件名',
            'body'    => '本文',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['recipients.0.to']);

        Mail::assertNothingSent();
    }
}
