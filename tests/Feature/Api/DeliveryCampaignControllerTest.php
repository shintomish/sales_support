<?php

namespace Tests\Feature\Api;

use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Email;
use App\Models\ProjectMailSource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeliveryCampaignControllerTest extends TestCase
{
    // ─── index ───

    public function test_index_returns_paginated_campaigns(): void
    {
        $this->actingAsUser();

        DeliveryCampaign::factory()->count(3)->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
        ]);

        $res = $this->getJson('/api/v1/delivery-campaigns');

        $res->assertOk()->assertJsonStructure(['data', 'current_page', 'total']);
        $this->assertCount(3, $res->json('data'));
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/delivery-campaigns')->assertUnauthorized();
    }

    public function test_index_filters_by_send_type(): void
    {
        $this->actingAsUser();

        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'send_type' => 'delivery',
            'subject'   => '一斉配信',
        ]);
        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'send_type' => 'proposal',
            'subject'   => '提案',
        ]);

        $res = $this->getJson('/api/v1/delivery-campaigns?send_type=proposal');

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('提案', $res->json('data.0.subject'));
    }

    public function test_index_excludes_proposals_when_flag_set(): void
    {
        $this->actingAsUser();

        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'send_type' => 'delivery',
            'subject'   => '一斉配信',
        ]);
        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'send_type' => 'proposal',
            'subject'   => '提案',
        ]);
        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'send_type' => 'engineer_proposal',
            'subject'   => '技術者提案',
        ]);

        $res = $this->getJson('/api/v1/delivery-campaigns?exclude_proposals=1');

        $res->assertOk();
        $subjects = collect($res->json('data'))->pluck('subject')->all();
        $this->assertContains('一斉配信', $subjects);
        $this->assertNotContains('提案', $subjects);
        $this->assertNotContains('技術者提案', $subjects);
    }

    public function test_index_searches_by_subject(): void
    {
        $this->actingAsUser();

        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'subject'   => '春の特別配信',
        ]);
        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'subject'   => '夏のキャンペーン',
        ]);

        $res = $this->getJson('/api/v1/delivery-campaigns?search=' . urlencode('春の'));

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('春の特別配信', $res->json('data.0.subject'));
    }

    public function test_index_only_returns_own_tenant_campaigns(): void
    {
        $this->actingAsUser();

        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'subject'   => '自テナント',
        ]);

        $otherTenant = Tenant::factory()->create();
        $otherUser   = User::factory()->tenantUser($otherTenant)->create();
        (new DeliveryCampaign)->forceFill([
            'tenant_id'   => $otherTenant->id,
            'user_id'     => $otherUser->id,
            'send_type'   => 'delivery',
            'subject'     => '他テナント',
            'body'        => '本文',
            'total_count' => 0,
        ])->save();

        $res = $this->getJson('/api/v1/delivery-campaigns');

        $res->assertOk();
        $subjects = collect($res->json('data'))->pluck('subject')->all();
        $this->assertContains('自テナント', $subjects);
        $this->assertNotContains('他テナント', $subjects);
    }

    public function test_index_filters_by_user_id(): void
    {
        $this->actingAsUser();

        $other = User::factory()->tenantUser(Tenant::find($this->authUser->tenant_id))->create();
        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'subject'   => '自分が送信',
        ]);
        DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $other->id,
            'subject'   => '他者が送信',
        ]);

        $res = $this->getJson("/api/v1/delivery-campaigns?user_id={$this->authUser->id}");

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('自分が送信', $res->json('data.0.subject'));
    }

    // ─── show ───

    public function test_show_returns_campaign_with_histories(): void
    {
        $this->actingAsUser();

        $campaign = DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
            'subject'   => '配信A',
        ]);
        DeliverySendHistory::create([
            'tenant_id'   => $this->authUser->tenant_id,
            'campaign_id' => $campaign->id,
            'email'       => 'test@example.com',
            'name'        => '宛先1',
            'status'      => 'sent',
        ]);

        $res = $this->getJson("/api/v1/delivery-campaigns/{$campaign->id}");

        $res->assertOk()
            ->assertJsonPath('subject', '配信A')
            ->assertJsonStructure(['id', 'subject', 'body', 'histories' => [['id', 'email', 'name', 'status']]]);
        $this->assertCount(1, $res->json('histories'));
    }

    public function test_show_returns_404_for_other_tenant(): void
    {
        $this->actingAsUser();

        $otherTenant = Tenant::factory()->create();
        $otherUser   = User::factory()->tenantUser($otherTenant)->create();
        $other = (new DeliveryCampaign)->forceFill([
            'tenant_id'   => $otherTenant->id,
            'user_id'     => $otherUser->id,
            'send_type'   => 'delivery',
            'subject'     => '他テナント',
            'body'        => '本文',
            'total_count' => 0,
        ]);
        $other->save();

        $this->getJson("/api/v1/delivery-campaigns/{$other->id}")->assertNotFound();
    }

    public function test_show_returns_404_when_not_found(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/delivery-campaigns/99999')->assertNotFound();
    }

    // ─── progress ───

    public function test_progress_returns_counts_and_sending_flag(): void
    {
        $this->actingAsUser();

        $campaign = DeliveryCampaign::factory()->create([
            'tenant_id'     => $this->authUser->tenant_id,
            'user_id'       => $this->authUser->id,
            'total_count'   => 100,
            'success_count' => 30,
            'failed_count'  => 2,
        ]);

        Cache::put("campaign_sending_{$campaign->id}", true, 60);

        $res = $this->getJson("/api/v1/delivery-campaigns/{$campaign->id}/progress");

        $res->assertOk()
            ->assertJson([
                'id'            => $campaign->id,
                'total_count'   => 100,
                'success_count' => 30,
                'failed_count'  => 2,
                'is_sending'    => true,
            ]);
    }

    public function test_progress_returns_false_when_no_cache(): void
    {
        $this->actingAsUser();

        $campaign = DeliveryCampaign::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'user_id'   => $this->authUser->id,
        ]);

        $res = $this->getJson("/api/v1/delivery-campaigns/{$campaign->id}/progress");

        $res->assertOk()->assertJsonPath('is_sending', false);
    }

    // ─── store: validation only（実送信は DeliveryCampaignService の責務）───

    public function test_store_rejects_unresolved_signature_placeholder(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/v1/delivery-campaigns', [
            'subject' => 'テスト件名',
            'body'    => '本文 <送信者氏名> です',
        ]);

        $res->assertStatus(422)
            ->assertJsonStructure(['message', 'placeholders']);
    }

    public function test_store_requires_subject_and_body(): void
    {
        $this->actingAsUser();

        $res = $this->postJson('/api/v1/delivery-campaigns', []);

        $res->assertStatus(422)->assertJsonValidationErrors(['subject', 'body']);
    }
}
