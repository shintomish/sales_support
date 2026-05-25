<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * markAllRead / is_read 更新の HOT 化。
 *
 * 問題: emails の is_read を変更する更新は、is_read を参照する index が 3 本
 * （emails_is_read_idx / emails_tenant_id_is_read_index /
 *  emails_tenant_unread_partial_idx）あるため必ず「非 HOT 更新」になり、
 * 本文 trgm GIN（emails_body_text_trgm_idx, 約234MB）を含む全 index に新タプルが
 * 挿入される。これが「全件既読」が数千件で数十分かかる原因（本番計測で HOT 率 7.9%）。
 *
 * 対策: is_read を参照する index を全廃すると is_read 更新が HOT 化でき、GIN を
 * 一切触らなくなる。さらに fillfactor を下げてページに更新用の空きを確保する。
 *   → 既存ページへ fillfactor を反映するには別途 `VACUUM FULL public.emails`
 *     （排他ロックを伴う・GIN 再構築含め推定 1〜3 分）が必要。ロックを伴うため
 *     migration には含めず、デプロイ手順として低トラフィック帯に手動実行する。
 *
 * 読み取り側: 未読バッジは EmailController::unreadCount のキャッシュ化で index
 * 非依存にした。デフォルト一覧は received_at 降順で is_read index 不使用。
 * 「未読のみ」フィルタは (tenant_id, received_at) index + ヒープフィルタで代替。
 *
 * CREATE/DROP INDEX CONCURRENTLY を使うためトランザクションを無効化。
 */
return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_is_read_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_tenant_id_is_read_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_tenant_unread_partial_idx');
        DB::statement('ALTER TABLE public.emails SET (fillfactor = 90)');
        // 反映には別途 VACUUM FULL が必要（上記コメント参照）
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.emails SET (fillfactor = 100)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_is_read_idx ON public.emails (is_read)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_tenant_id_is_read_index ON public.emails (tenant_id, is_read)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_tenant_unread_partial_idx ON public.emails (tenant_id) WHERE is_read = false');
    }
};
