<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * project_mail_sources の重複 email_id を統合し UNIQUE index に張り替える。
 *
 * 経緯:
 * - 2026-05-01 11:00:02〜11:00:51 の約 50 秒間で同じバッチが二重に走り、
 *   23 ペア(46 行)の完全重複が発生。score/status/subject すべて同一、
 *   他テーブル(requirement_match_results / mail_send_histories /
 *   delivery_campaigns)からの参照は両側とも 0 件と確認済 (2026-05-24)。
 * - 2026-05-24 に追加した project_mail_sources_email_id_index は重複を許容する
 *   non-unique 版だが、本来は 1 PMS = 1 email の一意関係。
 *
 * 手順:
 *   1) ROW_NUMBER で各 email_id の最古以外を DELETE
 *   2) UNIQUE index を CONCURRENTLY 新規作成
 *   3) 旧 non-unique index を CONCURRENTLY 削除
 *      (常に何らかの index が存在する状態を維持)
 *
 * 注意:
 * - CONCURRENTLY 使用のため $withinTransaction = false
 * - DELETE は単独 SQL なので 1 トランザクション内で完結
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // 1. 重複削除 (各 email_id で最古 id を残す)
        DB::statement(<<<'SQL'
            DELETE FROM project_mail_sources
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY email_id ORDER BY id) AS rn
                    FROM project_mail_sources
                ) t
                WHERE t.rn > 1
            )
        SQL);

        // 2. UNIQUE index を CONCURRENTLY 作成
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS project_mail_sources_email_id_unique ON public.project_mail_sources USING btree (email_id)');

        // 3. 旧 non-unique index を CONCURRENTLY 削除
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.project_mail_sources_email_id_index');
    }

    public function down(): void
    {
        // 旧 non-unique index を復元
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS project_mail_sources_email_id_index ON public.project_mail_sources USING btree (email_id)');
        // UNIQUE index を削除
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.project_mail_sources_email_id_unique');
        // 削除済み重複行は復元不可
    }
};
