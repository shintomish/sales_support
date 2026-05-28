<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * emails の is_read UPDATE を HOT 化するため、is_read 関連 index 3 本を DROP し
 * fillfactor=90 を設定する (project_emails_disk_io_2026_05_25)。
 *
 * 背景: 本番 HOT 率 6.8% / markAllRead で 502 タイムアウト多発。
 * is_read が含まれる index(完全/複合/部分)があると HOT 不成立で
 *   GIN(368MB) を含む全 index に新タプル → WAL/IO 急増。
 *
 * 影響:
 *  - unread-count / unread フィルター: 部分 index 喪失で Seq Scan に降格
 *    (105k 行・79MB heap で許容範囲。さらに EmailController::unreadCount は
 *     60s キャッシュで I/O を抑える ─ 同コミット参照)。
 *  - HOT 化により markAllRead 200件/バッチが大幅軽量化、
 *    日常 insert/category 更新も WAL 減 (本マイグレ単独で復活ループは無し)。
 *
 * VACUUM FULL は別途メンテ窓で手動実行 (短時間排他ロック)。
 */
return new class extends Migration
{
    /** マイグレーションは DDL のみで完結 (CONCURRENTLY 不要・index 削除はメタデータ更新のみ) */
    public $withinTransaction = true;

    public function up(): void
    {
        // is_read を含む index を 3 本とも削除して HOT 更新可能化
        DB::statement('DROP INDEX IF EXISTS public.emails_is_read_idx');
        DB::statement('DROP INDEX IF EXISTS public.emails_tenant_id_is_read_index');
        DB::statement('DROP INDEX IF EXISTS public.emails_tenant_unread_partial_idx');

        // 既存タプルの行内に更新余地を作る (新規 INSERT から有効。既存行は VACUUM FULL で再配置)
        DB::statement('ALTER TABLE public.emails SET (fillfactor = 90)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.emails RESET (fillfactor)');

        // ロールバック時は元の 3 本を再作成 (CONCURRENTLY は migration 既定の transaction と非互換のため
        // 手動 down 時のみ。通常運用では本マイグレを戻さない)
        DB::statement('CREATE INDEX IF NOT EXISTS emails_is_read_idx ON public.emails USING btree (is_read)');
        DB::statement('CREATE INDEX IF NOT EXISTS emails_tenant_id_is_read_index ON public.emails USING btree (tenant_id, is_read)');
        DB::statement('CREATE INDEX IF NOT EXISTS emails_tenant_unread_partial_idx ON public.emails USING btree (tenant_id) WHERE (is_read = false)');
    }
};
