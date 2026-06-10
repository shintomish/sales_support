<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use Tests\Pgsql\PgsqlTestCase;

/**
 * POST /api/v1/{project,engineer}-mails/{id}/memo の characterization テスト。
 *
 * 右ペインからの「メモ・備考」追記。手動登録 (source='manual') のダミー email の
 * body_text 末尾にのみ追記し、IMAP 受信メール (source='imap') は改変しない。
 */
class MailSourceAppendMemoTest extends PgsqlTestCase
{
    public function test_project_manual_memo_is_appended_to_body(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        $email = Email::factory()->create(['tenant_id' => $tenantId, 'body_text' => '既存の本文']);
        $pms = ProjectMailSource::factory()->create([
            'tenant_id' => $tenantId, 'email_id' => $email->id, 'source' => 'manual',
        ]);

        $res = $this->postJson("/api/v1/project-mails/{$pms->id}/memo", ['body_text' => '電話で単価交渉可と確認']);

        $res->assertOk();
        $this->assertSame("既存の本文\n\n電話で単価交渉可と確認", $email->fresh()->body_text);
    }

    public function test_project_imap_memo_is_rejected(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        $email = Email::factory()->create(['tenant_id' => $tenantId, 'body_text' => '受信メール本文']);
        $pms = ProjectMailSource::factory()->create([
            'tenant_id' => $tenantId, 'email_id' => $email->id, 'source' => 'imap',
        ]);

        $res = $this->postJson("/api/v1/project-mails/{$pms->id}/memo", ['body_text' => 'メモ']);

        $res->assertStatus(422);
        $this->assertSame('受信メール本文', $email->fresh()->body_text, 'IMAP 受信メールの本文は改変されない');
    }

    public function test_engineer_manual_memo_is_appended_to_body(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        $email = Email::factory()->create(['tenant_id' => $tenantId, 'body_text' => 'スキル: Java']);
        $ems = EngineerMailSource::factory()->create([
            'tenant_id' => $tenantId, 'email_id' => $email->id, 'source' => 'manual',
        ]);

        $res = $this->postJson("/api/v1/engineer-mails/{$ems->id}/memo", ['body_text' => '稼働は7月から']);

        $res->assertOk();
        $this->assertSame("スキル: Java\n\n稼働は7月から", $email->fresh()->body_text);
    }

    public function test_memo_requires_body_text(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        $email = Email::factory()->create(['tenant_id' => $tenantId]);
        $pms = ProjectMailSource::factory()->create([
            'tenant_id' => $tenantId, 'email_id' => $email->id, 'source' => 'manual',
        ]);

        $this->postJson("/api/v1/project-mails/{$pms->id}/memo", [])->assertStatus(422);
    }
}
