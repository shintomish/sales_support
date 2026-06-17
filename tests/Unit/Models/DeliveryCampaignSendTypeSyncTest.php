<?php

namespace Tests\Unit\Models;

use App\Models\DeliveryCampaign;
use Tests\UnitTestCase;

/**
 * 提案スレッド系 send_type の「4箇所同期」を守る回帰ガード（docs/740 G2）。
 *
 * CLAUDE.md「確定済み設計判断」より、提案スレッド系の whereIn を増減する時は
 * index(exclude_proposals) / proposalThreads(本体+campaignsByThread) /
 * ProjectMailController::thread / EngineerMailController::thread を必ず同期する。
 *
 * これらを DeliveryCampaign の 4 定数から派生させ（PROJECT/ENGINEER サブセット →
 * PROPOSAL_THREAD = 和集合 → EXCLUDE = +self_reply）、ドリフトを構造的に防ぐ。
 * 本テストは定数間の派生関係と、各コントローラが定数を参照し続けていることを検証する。
 */
class DeliveryCampaignSendTypeSyncTest extends UnitTestCase
{
    public function test_proposal_thread_types_is_union_of_project_and_engineer_subsets(): void
    {
        $union = array_merge(
            DeliveryCampaign::PROJECT_PROPOSAL_TYPES,
            DeliveryCampaign::ENGINEER_PROPOSAL_TYPES,
        );

        sort($union);
        $thread = DeliveryCampaign::PROPOSAL_THREAD_TYPES;
        sort($thread);

        $this->assertSame($union, $thread, 'PROPOSAL_THREAD_TYPES が案件∪技術者サブセットと一致しない（4箇所同期崩れ）');
    }

    public function test_project_and_engineer_subsets_are_disjoint(): void
    {
        $overlap = array_intersect(
            DeliveryCampaign::PROJECT_PROPOSAL_TYPES,
            DeliveryCampaign::ENGINEER_PROPOSAL_TYPES,
        );

        $this->assertSame([], array_values($overlap), '案件側と技術者側で send_type が重複している');
    }

    public function test_exclude_from_delivery_is_proposal_threads_plus_self_reply(): void
    {
        $expected = array_merge(DeliveryCampaign::PROPOSAL_THREAD_TYPES, ['self_reply']);

        sort($expected);
        $actual = DeliveryCampaign::EXCLUDE_FROM_DELIVERY_TYPES;
        sort($actual);

        $this->assertSame($expected, $actual, 'exclude_proposals 集合が「提案スレッド系 + self_reply」と一致しない');
    }

    public function test_self_reply_is_not_a_proposal_thread_type(): void
    {
        // self_reply は /emails 個別返信。専用「返信履歴」タブのみで表示し、
        // 提案スレッド系には含めない（4箇所同期の対象外）。
        $this->assertNotContains('self_reply', DeliveryCampaign::PROPOSAL_THREAD_TYPES);
        $this->assertContains('self_reply', DeliveryCampaign::EXCLUDE_FROM_DELIVERY_TYPES);
    }

    public function test_delivery_is_never_treated_as_a_thread(): void
    {
        // 'delivery'（一斉配信）は 1対多でスレッド概念に合わない。いずれの集合にも含めない。
        foreach ([
            DeliveryCampaign::PROJECT_PROPOSAL_TYPES,
            DeliveryCampaign::ENGINEER_PROPOSAL_TYPES,
            DeliveryCampaign::PROPOSAL_THREAD_TYPES,
            DeliveryCampaign::EXCLUDE_FROM_DELIVERY_TYPES,
        ] as $set) {
            $this->assertNotContains('delivery', $set);
        }
    }

    public function test_no_duplicate_send_types_in_any_constant(): void
    {
        foreach ([
            'PROJECT_PROPOSAL_TYPES'      => DeliveryCampaign::PROJECT_PROPOSAL_TYPES,
            'ENGINEER_PROPOSAL_TYPES'     => DeliveryCampaign::ENGINEER_PROPOSAL_TYPES,
            'PROPOSAL_THREAD_TYPES'       => DeliveryCampaign::PROPOSAL_THREAD_TYPES,
            'EXCLUDE_FROM_DELIVERY_TYPES' => DeliveryCampaign::EXCLUDE_FROM_DELIVERY_TYPES,
        ] as $name => $set) {
            $this->assertSame(array_values(array_unique($set)), array_values($set), "{$name} に重複 send_type がある");
        }
    }

    /**
     * 4箇所が定数を参照し続けていること（再ハードコード防止）。
     * 将来うっかり whereIn に生配列を直書きすると、定数トークンが消えて fail する。
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('syncSiteProvider')]
    public function test_controller_references_the_shared_constant(string $relativePath, string $constantToken): void
    {
        $source = file_get_contents(base_path($relativePath));
        $this->assertNotFalse($source, "ソースが読めない: {$relativePath}");
        $this->assertStringContainsString(
            $constantToken,
            $source,
            "{$relativePath} が {$constantToken} を参照していない（send_type を再ハードコードした疑い）",
        );
    }

    public static function syncSiteProvider(): array
    {
        return [
            'index exclude_proposals (除外集合)' => [
                'app/Http/Controllers/Api/DeliveryCampaignController.php',
                'DeliveryCampaign::EXCLUDE_FROM_DELIVERY_TYPES',
            ],
            'proposalThreads (提案スレッド全集合)' => [
                'app/Http/Controllers/Api/DeliveryCampaignController.php',
                'DeliveryCampaign::PROPOSAL_THREAD_TYPES',
            ],
            'ProjectMailController::thread (案件サブセット)' => [
                'app/Http/Controllers/Api/ProjectMailController.php',
                'DeliveryCampaign::PROJECT_PROPOSAL_TYPES',
            ],
            'EngineerMailController::thread (技術者サブセット)' => [
                'app/Http/Controllers/Api/EngineerMailController.php',
                'DeliveryCampaign::ENGINEER_PROPOSAL_TYPES',
            ],
        ];
    }
}
