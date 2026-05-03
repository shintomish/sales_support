<?php

namespace Tests\Pgsql\Feature;

use App\Models\DeliveryCampaign;
use App\Models\DeliverySendHistory;
use App\Models\Email;
use App\Models\EngineerMailSource;
use App\Models\ProjectMailSource;
use Tests\Pgsql\PgsqlTestCase;

/**
 * /api/v1/proposal-threads の Feature テスト。
 *
 * proposalThreads は PostgreSQL 固有の `::text` キャスト・`||` 文字列結合・
 * `COALESCE` を駆使した raw SQL を使うため、SQLite では検証不能。
 */
class ProposalThreadsTest extends PgsqlTestCase
{
    private function makeProjectThread(string $title, string $customerName, string $status = 'new'): array
    {
        $email = Email::factory()->create(['tenant_id' => $this->authUser->tenant_id]);
        $pms   = ProjectMailSource::factory()->create([
            'tenant_id'     => $this->authUser->tenant_id,
            'email_id'      => $email->id,
            'title'         => $title,
            'customer_name' => $customerName,
            'status'        => $status,
        ]);
        $campaign = DeliveryCampaign::factory()->create([
            'tenant_id'       => $this->authUser->tenant_id,
            'user_id'         => $this->authUser->id,
            'send_type'       => 'proposal',
            'project_mail_id' => $pms->id,
            'subject'         => "Re: {$title}",
            'sent_at'         => now()->subMinutes(rand(1, 60)),
            'success_count'   => 1,
        ]);
        return ['pms' => $pms, 'campaign' => $campaign];
    }

    private function makeEngineerThread(string $name, string $status = 'new'): array
    {
        $email = Email::factory()->create(['tenant_id' => $this->authUser->tenant_id]);
        $ems   = EngineerMailSource::factory()->create([
            'tenant_id' => $this->authUser->tenant_id,
            'email_id'  => $email->id,
            'name'      => $name,
            'status'    => $status,
        ]);
        $campaign = DeliveryCampaign::factory()->create([
            'tenant_id'               => $this->authUser->tenant_id,
            'user_id'                 => $this->authUser->id,
            'send_type'               => 'engineer_proposal',
            'engineer_mail_source_id' => $ems->id,
            'subject'                 => "技術者ご紹介: {$name}",
            'sent_at'                 => now()->subMinutes(rand(1, 60)),
            'success_count'           => 1,
        ]);
        return ['ems' => $ems, 'campaign' => $campaign];
    }

    public function test_returns_grouped_threads_with_correct_types(): void
    {
        $this->actingAsUser();

        $project  = $this->makeProjectThread('Laravel 案件', '株式会社A');
        $engineer = $this->makeEngineerThread('山田太郎');

        $res = $this->getJson('/api/v1/proposal-threads');

        $res->assertOk();
        $data = collect($res->json('data'));
        $this->assertSame(2, $data->count());

        $types = $data->pluck('type')->sort()->values()->all();
        $this->assertSame(['engineer', 'project'], $types);

        $projThread = $data->firstWhere('type', 'project');
        $this->assertSame('株式会社A', $projThread['customer_name']);
        $this->assertSame('Laravel 案件', $projThread['title']);

        $engThread = $data->firstWhere('type', 'engineer');
        $this->assertSame('山田太郎', $engThread['title']);
        $this->assertNull($engThread['customer_name']);
    }

    public function test_groups_multiple_campaigns_per_thread(): void
    {
        $this->actingAsUser();

        $project = $this->makeProjectThread('PHP 案件', '株式会社B');
        // 同じ project_mail_id で 2 件目のキャンペーン
        DeliveryCampaign::factory()->create([
            'tenant_id'       => $this->authUser->tenant_id,
            'user_id'         => $this->authUser->id,
            'send_type'       => 'proposal',
            'project_mail_id' => $project['pms']->id,
            'subject'         => '再提案',
            'sent_at'         => now(),
            'success_count'   => 1,
        ]);

        $res = $this->getJson('/api/v1/proposal-threads');

        $res->assertOk();
        $data = collect($res->json('data'));
        $this->assertSame(1, $data->count(), '同一 project_mail_id は1スレッドにグループ化される');
        $this->assertSame(2, $data->first()['thread_count']);
    }

    public function test_filters_by_type_project(): void
    {
        $this->actingAsUser();

        $this->makeProjectThread('Laravel 案件', '株式会社C');
        $this->makeEngineerThread('鈴木花子');

        $res = $this->getJson('/api/v1/proposal-threads?type=project');

        $res->assertOk();
        $data = collect($res->json('data'));
        $this->assertSame(1, $data->count());
        $this->assertSame('project', $data->first()['type']);
    }

    public function test_filters_by_type_engineer(): void
    {
        $this->actingAsUser();

        $this->makeProjectThread('Laravel 案件', '株式会社D');
        $this->makeEngineerThread('佐藤一郎');

        $res = $this->getJson('/api/v1/proposal-threads?type=engineer');

        $res->assertOk();
        $data = collect($res->json('data'));
        $this->assertSame(1, $data->count());
        $this->assertSame('engineer', $data->first()['type']);
        $this->assertSame('佐藤一郎', $data->first()['title']);
    }

    public function test_search_uses_ilike_across_project_and_engineer(): void
    {
        $this->actingAsUser();

        $this->makeProjectThread('Laravel 案件', '山田商事');
        $this->makeProjectThread('PHP 案件', '株式会社B');
        $this->makeEngineerThread('山田太郎');

        $res = $this->getJson('/api/v1/proposal-threads?search=' . urlencode('山田'));

        $res->assertOk();
        $data = collect($res->json('data'));
        $this->assertSame(2, $data->count(), 'project の customer_name と engineer の name の両方にヒット');
    }

    public function test_has_unread_reply_when_reply_email_is_unread(): void
    {
        $this->actingAsUser();

        $project = $this->makeProjectThread('Laravel 案件', '株式会社E');

        $unreadReplyEmail = Email::factory()->create([
            'tenant_id'   => $this->authUser->tenant_id,
            'subject'     => '返信です',
            'is_read'     => false,
            'received_at' => now(),
        ]);

        DeliverySendHistory::create([
            'tenant_id'      => $this->authUser->tenant_id,
            'campaign_id'    => $project['campaign']->id,
            'email'          => 'reply@example.com',
            'name'           => 'ご担当',
            'status'         => 'sent',
            'replied_at'     => now(),
            'reply_email_id' => $unreadReplyEmail->id,
        ]);

        $res = $this->getJson('/api/v1/proposal-threads');

        $res->assertOk();
        $thread = $res->json('data.0');
        $this->assertTrue($thread['has_unread_reply']);
    }
}
