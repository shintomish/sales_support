<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use App\Models\ProjectMailSource;
use Tests\Pgsql\PgsqlTestCase;

/**
 * /api/v1/project-mails/reextract-all の characterization テスト。
 *
 * docs/730 #22 に基づき、中期ジョブ化 (rescore-jobs 型) で挙動を変える前に
 * 現状のバッチ挙動を pin する:
 *   (a) cross-tenant 不変 — 他テナントの pms は更新されない
 *   (b) offset/limit が正しく適用される
 *   (c) email を伴わない pms は count に加算されずスキップ
 */
class ProjectMailReextractAllTest extends PgsqlTestCase
{
    public function test_reextract_all_does_not_affect_other_tenants(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $myTenantId = $this->authUser->tenant_id;
        $other = $this->createUserInAnotherTenant();

        // 自テナント: pms × 2 (extract で title が再計算され得る)
        $myEmail1 = Email::factory()->create(['tenant_id' => $myTenantId, 'subject' => 'Java エンジニア募集', 'body_text' => '稼働開始: 2026/07 単価: 80万円']);
        $myEmail2 = Email::factory()->create(['tenant_id' => $myTenantId, 'subject' => 'PHP 案件', 'body_text' => '勤務地: 東京 単価: 70万円']);
        $myPms1 = ProjectMailSource::factory()->create(['tenant_id' => $myTenantId, 'email_id' => $myEmail1->id, 'title' => 'OLD_TITLE_A']);
        $myPms2 = ProjectMailSource::factory()->create(['tenant_id' => $myTenantId, 'email_id' => $myEmail2->id, 'title' => 'OLD_TITLE_B']);

        // 他テナント: 同条件の pms (触られないことを確認)
        $otherEmail = Email::factory()->create(['tenant_id' => $other->tenant_id, 'subject' => '他テナントの案件', 'body_text' => 'noise']);
        $otherPms   = ProjectMailSource::factory()->create([
            'tenant_id' => $other->tenant_id, 'email_id' => $otherEmail->id, 'title' => 'OTHER_TENANT_TITLE',
        ]);

        $res = $this->postJson('/api/v1/project-mails/reextract-all');

        $res->assertOk();
        $this->assertSame(2, $res->json('count'), '自テナントの 2 件のみ更新される');

        // 他テナントの title は不変
        $this->assertSame('OTHER_TENANT_TITLE',
            ProjectMailSource::withoutGlobalScopes()->find($otherPms->id)->title);
    }

    public function test_reextract_all_respects_offset(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        // 5 件作成 (id 昇順で並ぶ)
        for ($i = 0; $i < 5; $i++) {
            $email = Email::factory()->create(['tenant_id' => $tenantId, 'subject' => "案件 #{$i}"]);
            ProjectMailSource::factory()->create(['tenant_id' => $tenantId, 'email_id' => $email->id]);
        }

        // offset=2 で残 3 件を処理 (controller は batchSize=300 固定)
        $res = $this->postJson('/api/v1/project-mails/reextract-all?offset=2');

        $res->assertOk();
        $this->assertSame(3, $res->json('count'), 'offset=2 で残り 3 件 (id 3,4,5) が処理される');
        $this->assertSame(0, $res->json('remaining'), 'total=5 から offset+count=5 を引いた残数は 0');
    }

    public function test_reextract_all_skips_rows_without_email(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        // email を持つ pms × 2
        $email1 = Email::factory()->create(['tenant_id' => $tenantId, 'subject' => '通常']);
        $email2 = Email::factory()->create(['tenant_id' => $tenantId, 'subject' => '通常']);
        ProjectMailSource::factory()->create(['tenant_id' => $tenantId, 'email_id' => $email1->id]);
        ProjectMailSource::factory()->create(['tenant_id' => $tenantId, 'email_id' => $email2->id]);

        // email_id を持つが email レコードが消えている孤児 (with('email') で null になり continue 対象)
        $email3 = Email::factory()->create(['tenant_id' => $tenantId, 'subject' => '一時的']);
        $orphanPms = ProjectMailSource::factory()->create(['tenant_id' => $tenantId, 'email_id' => $email3->id]);
        $email3->delete();

        $res = $this->postJson('/api/v1/project-mails/reextract-all');

        $res->assertOk();
        $this->assertSame(2, $res->json('count'), 'email を持つ 2 件のみカウントされ、孤児はスキップ');
    }
}
