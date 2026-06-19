<?php

namespace Tests\Pgsql\Feature;

use App\Models\EmailDeliveryTemplate;
use Tests\Pgsql\PgsqlTestCase;

/**
 * 配信テンプレライブラリ (email_delivery_templates) の CRUD とテナント分離(IDOR)ガード。
 *
 * 2026-06-19 営業会議 §4 / AItem3「配信用とリアル案件用でテンプレを明確化」で新設。
 * テナント共有モデル(TenantScope 適用)なので、
 *  - 一覧は自テナント分のみ返り purpose で絞れること
 *  - 他テナントのテンプレを id 指定で更新/削除できない(404)こと
 * を回帰として固定する。
 */
class EmailDeliveryTemplateTest extends PgsqlTestCase
{
    public function test_crud_and_purpose_filter(): void
    {
        $this->actingAsUser();

        // 作成（standard / real_spot）
        $this->postJson('/api/v1/email-delivery-templates', [
            'purpose'   => 'standard',
            'name'      => '6月配信用',
            'subject'   => '【ご案内】稼働可能なエンジニア',
            'body_text' => '<%Name%> 様',
        ])->assertCreated();

        $real = $this->postJson('/api/v1/email-delivery-templates', [
            'purpose'   => 'real_spot',
            'name'      => '超リアル案件用',
            'subject'   => '【即日】スポット案件',
            'body_text' => '超リアル',
        ])->assertCreated()->json();

        $this->assertSame('real_spot', $real['purpose']);
        $this->assertSame($this->authUser->tenant_id, $real['tenant_id']);
        $this->assertSame($this->authUser->id, $real['user_id']);

        // 一覧: purpose 絞り込み
        $res = $this->getJson('/api/v1/email-delivery-templates?purpose=real_spot');
        $res->assertOk();
        $this->assertCount(1, $res->json());
        $this->assertSame('超リアル案件用', $res->json('0.name'));

        // 更新
        $this->putJson("/api/v1/email-delivery-templates/{$real['id']}", [
            'purpose'   => 'real_spot',
            'name'      => '超リアル案件用(改)',
            'is_active' => false,
        ])->assertOk()->assertJson(['name' => '超リアル案件用(改)', 'is_active' => false]);

        // 削除
        $this->deleteJson("/api/v1/email-delivery-templates/{$real['id']}")->assertOk();
        $this->assertDatabaseMissing('email_delivery_templates', ['id' => $real['id']]);
    }

    public function test_cross_tenant_template_is_not_accessible(): void
    {
        $this->actingAsUser();

        // 別テナントのテンプレを直接作成
        $otherUser = $this->createUserInAnotherTenant();
        $foreign = EmailDeliveryTemplate::create([
            'tenant_id' => $otherUser->tenant_id,
            'user_id'   => $otherUser->id,
            'purpose'   => 'standard',
            'name'      => '他社テンプレ',
        ]);

        // 一覧には出てこない
        $this->getJson('/api/v1/email-delivery-templates')
            ->assertOk()
            ->assertJsonMissing(['name' => '他社テンプレ']);

        // id 指定の更新/削除は 404（TenantScope による IDOR ガード）
        $this->putJson("/api/v1/email-delivery-templates/{$foreign->id}", [
            'purpose' => 'standard',
            'name'    => '乗っ取り',
        ])->assertNotFound();

        $this->deleteJson("/api/v1/email-delivery-templates/{$foreign->id}")
            ->assertNotFound();

        // 他テナントのレコードは無傷
        $this->assertDatabaseHas('email_delivery_templates', [
            'id'   => $foreign->id,
            'name' => '他社テンプレ',
        ]);
    }
}
