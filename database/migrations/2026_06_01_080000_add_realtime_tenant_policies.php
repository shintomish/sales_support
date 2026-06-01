<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Realtime テーブルに tenant スコープ RLS policy を追加。
 *
 * 背景:
 *   直前の migration (2026_06_01_073500) で anon/authenticated の default GRANT を REVOKE し、
 *   Realtime 5 テーブル (activities/business_cards/deals/emails/tasks) のみ
 *   authenticated SELECT を再付与した。
 *   ただし RLS は default deny かつ business_cards のみ qual=true (全 tenant 横断読み可能)
 *   という不整合状態。authenticated で Realtime 購読しても他 4 テーブルは行データが見えず、
 *   business_cards は逆に cross-tenant に見えてしまうという問題があった。
 *
 * 解決:
 *   1. SECURITY DEFINER 関数 public.current_user_tenant_id() を作成。
 *      auth.uid() (Supabase JWT の sub) → public.users.supabase_uid → users.tenant_id
 *      を取得する。SECURITY DEFINER で BYPASSRLS なロールで実行されるため users への
 *      アクセスは内部で完結する。
 *   2. business_cards の "authenticated can select" (qual=true) を削除して
 *      tenant 制限 policy に差し替え。
 *   3. 残り 4 テーブル (tasks/deals/activities/emails) にも同形式の tenant 制限
 *      SELECT policy を新規追加。
 *
 * 影響範囲:
 *   - authenticated (Realtime 購読クライアント): 自テナント行のみ受け取れる (正しい挙動)
 *   - service_role (Laravel): BYPASSRLS なので policy 無視で全件アクセス継続
 *   - anon: GRANT も無いので無影響
 *   - business_cards の cross-tenant 漏れがふさがる (副次的なセキュリティ向上)
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // sqlite (test) は対象外
        }

        // 1. tenant_id 解決関数 (SECURITY DEFINER で RLS をバイパスして users を引く)
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.current_user_tenant_id()
            RETURNS bigint
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public
            AS $$
              SELECT tenant_id
              FROM public.users
              WHERE supabase_uid = auth.uid()
              LIMIT 1
            $$
        SQL);

        // authenticated に EXECUTE 権限 (policy 内呼び出しに必要)
        DB::statement('GRANT EXECUTE ON FUNCTION public.current_user_tenant_id() TO authenticated');

        // 2. business_cards の旧 unconditional policy を撤去
        DB::statement('DROP POLICY IF EXISTS "authenticated can select" ON public.business_cards');

        // 3. Realtime 5 テーブルに tenant 制限 SELECT policy を統一追加
        $realtimeTables = ['tasks', 'deals', 'activities', 'business_cards', 'emails'];
        foreach ($realtimeTables as $t) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_select ON public.\"{$t}\"");
            DB::statement(<<<SQL
                CREATE POLICY tenant_isolation_select ON public."{$t}"
                FOR SELECT
                TO authenticated
                USING (tenant_id = public.current_user_tenant_id())
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['tasks', 'deals', 'activities', 'business_cards', 'emails'] as $t) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_select ON public.\"{$t}\"");
        }

        // 互換のため business_cards の元の qual=true policy を復元 (運用判断で削除可)
        DB::statement(<<<'SQL'
            CREATE POLICY "authenticated can select" ON public.business_cards
            FOR SELECT TO authenticated USING (true)
        SQL);

        DB::statement('DROP FUNCTION IF EXISTS public.current_user_tenant_id()');
    }
};
