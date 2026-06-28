<?php

namespace Tests\Pgsql\Feature;

use App\Models\Email;
use App\Models\ProjectMailSource;
use App\Services\ProjectMailScoringService;
use Tests\Pgsql\PgsqlTestCase;

/**
 * ProjectMailScoringService::rescoreAll の cursor 化（OOM 対策・Sentry 2026-06-27）を
 * 実 PostgreSQL で検証する。get()→cursor() + 逐次 update（トランザクション無し）が
 * pgsql 上で正しく動く（カーソル反復中の UPDATE が破綻しない）ことを pin する。
 */
class ProjectMailRescoreCursorTest extends PgsqlTestCase
{
    public function test_rescore_all_は_cursorで本文ありの行を再スコアする(): void
    {
        $this->actingAsUser(['role' => 'tenant_admin']);
        $tenantId = $this->authUser->tenant_id;

        // 本文あり 3 件（再スコア対象）
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $email = Email::factory()->create([
                'tenant_id' => $tenantId,
                'subject'   => "Java エンジニア募集 #{$i}",
                'body_text' => '単価: 80万円 勤務地: 東京 Java 開発 即日',
            ]);
            $ids[] = ProjectMailSource::factory()->create([
                'tenant_id' => $tenantId, 'email_id' => $email->id, 'score' => 0, 'engine' => 'ai',
            ])->id;
        }

        $count = app(ProjectMailScoringService::class)->rescoreAll(300, 0, $tenantId);

        $this->assertSame(3, $count);
        // cursor 反復中の update が反映されている（engine='rule' は本文ありの通常経路でのみ立つ）
        foreach ($ids as $id) {
            $this->assertSame('rule', ProjectMailSource::withoutGlobalScopes()->find($id)->engine);
        }
    }
}
