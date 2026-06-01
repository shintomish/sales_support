<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase default 権限 (anon / authenticated) の REVOKE。
 *
 * 背景:
 *   Supabase 新規プロジェクトの初期状態では public スキーマの全テーブルに対し
 *   anon / authenticated / service_role の 3 ロールへ ALL (SELECT/INSERT/UPDATE/
 *   DELETE/TRIGGER/TRUNCATE/REFERENCES) が GRANT されている。RLS が有効なため
 *   現状は default deny で守られているが、誤って permissive policy を入れると
 *   即座に外部公開 (PostgREST / Realtime) されるリスクが残る。
 *
 *   2026-10-30 Supabase は新規プロジェクトの default GRANT を廃止する方針だが、
 *   既存プロジェクトには適用されないため、明示的に REVOKE する必要がある
 *   ([[feedback_supabase_data_api_grant]])。
 *
 * 方針:
 *   1. anon / authenticated への ALL を public 配下の全テーブルから REVOKE
 *   2. Realtime publication 含まれる 5 テーブル (activities/business_cards/deals/
 *      emails/tasks) のみ authenticated SELECT を明示再付与 (supabase-js Realtime
 *      購読で必要)。フロントの実コードを grep で確認済 (.from() による直接読み無し)
 *   3. service_role は Laravel が接続するロールなので一切触らない
 *
 * 影響範囲:
 *   - フロント Realtime は 5 テーブルだけ SELECT 可なので継続動作
 *   - Laravel API は全て service_role 経由のため無影響
 *   - 万一 supabase-js が .from() 直接読みを追加した場合は明示 GRANT が必要に
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // sqlite (test) は対象外
        }

        // 1. public 配下の全テーブルから anon / authenticated を一括 REVOKE
        DB::statement('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM anon');
        DB::statement('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM authenticated');

        // 2. Realtime publication 含まれる 5 テーブルのみ authenticated SELECT 再付与
        //    (CLAUDE.md REALTIME_TABLES と一致 / pg_publication_tables で実測確認済)
        $realtimeTables = ['activities', 'business_cards', 'deals', 'emails', 'tasks'];
        foreach ($realtimeTables as $t) {
            DB::statement("GRANT SELECT ON public.\"{$t}\" TO authenticated");
        }

        // 3. service_role は触らない (Laravel が依存)
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Supabase 初期状態へ戻す (anon / authenticated に ALL 再付与)
        // ※ rollback は permissive な状態に戻すので運用上はあえて呼ばない方が安全
        DB::statement('GRANT ALL ON ALL TABLES IN SCHEMA public TO anon');
        DB::statement('GRANT ALL ON ALL TABLES IN SCHEMA public TO authenticated');
    }
};
