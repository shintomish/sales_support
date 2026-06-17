<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use App\Models\RescoreJob;
use App\Services\RescoreJobRunner;
use Illuminate\Support\Facades\DB;
use Tests\Pgsql\PgsqlTestCase;

/**
 * markAllRead の LIMIT バッチ境界を守る回帰ガード（docs/740 G4）。
 *
 * 全未読を単一 UPDATE すると emails の index 保守で statement_timeout になるため、
 * RescoreJobRunner は MARK_READ_BATCH_SIZE(=200) 件ずつ pluck→whereIn UPDATE のループで処理する
 * (CLAUDE memory: emails_bulk_write_batch)。本テストは「バッチサイズを超える未読を、
 * 単一 UPDATE でなく複数バッチで処理し、最終的に全件既読・ジョブ完了する」ことを検証する。
 *
 * SET LOCAL statement_timeout 等 Postgres 固有処理を含むため Pgsql テストとして実行する。
 */
class MarkAllReadBatchTest extends PgsqlTestCase
{
    public function test_mark_all_read_processes_large_unread_in_limit_batches(): void
    {
        $this->actingAsUser();
        $tenantId = $this->authUser->tenant_id;

        // バッチサイズ(200)を超える 250 件の未読を作成 → 2 バッチに分割されるはず。
        Email::factory()->count(250)->create([
            'tenant_id' => $tenantId,
            'is_read'   => false,
        ]);

        $res = $this->postJson('/api/v1/emails/mark-all-read');
        $res->assertStatus(202)->assertJsonPath('job.total_count', 250);

        // emails への UPDATE 文の回数を計測（単一一括 UPDATE でないことの証明）。
        $emailUpdateBatches = 0;
        DB::listen(function ($q) use (&$emailUpdateBatches) {
            if (preg_match('/update\s+"emails"/i', $q->sql)) {
                $emailUpdateBatches++;
            }
        });

        // tick は時間バジェット内で複数バッチをまとめて処理する（1 回で 250 件完走しうる）。
        app(RescoreJobRunner::class)->tick();

        // 全件既読・ジョブ完了
        $this->assertSame(
            0,
            Email::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_read', false)->count(),
            '全未読が既読化されていない',
        );
        $job = RescoreJob::where('type', RescoreJob::TYPE_MARK_READ)->latest('id')->first();
        $this->assertSame(RescoreJob::STATUS_COMPLETED, $job->status, 'ジョブが完了していない');
        $this->assertSame(250, $job->processed_count);

        // 250 > バッチサイズ 200 → emails への UPDATE は複数回（LIMIT バッチで分割）。
        $this->assertGreaterThanOrEqual(
            2,
            $emailUpdateBatches,
            "単一一括 UPDATE になっている（LIMIT バッチで分割されていない）: update回数={$emailUpdateBatches}",
        );
    }

    public function test_mark_all_read_does_not_touch_other_tenants_unread(): void
    {
        $this->actingAsUser();
        $tenantId = $this->authUser->tenant_id;
        Email::factory()->count(3)->create(['tenant_id' => $tenantId, 'is_read' => false]);

        // 別テナントの未読
        $other = \App\Models\Tenant::factory()->create();
        Email::factory()->count(2)->create(['tenant_id' => $other->id, 'is_read' => false]);

        $this->postJson('/api/v1/emails/mark-all-read')->assertStatus(202);
        app(RescoreJobRunner::class)->tick();

        $this->assertSame(0, Email::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_read', false)->count());
        $this->assertSame(2, Email::withoutGlobalScopes()->where('tenant_id', $other->id)->where('is_read', false)->count(), '他テナントの未読が既読化された');
    }
}
