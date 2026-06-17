<?php

namespace Tests\Pgsql\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Pgsql\PgsqlTestCase;

/**
 * 新規テーブルの RLS 漏れを検出する回帰ガード（docs/740 G3）。
 *
 * CLAUDE.md「重要な注意点」より、新規テーブル作成 migration では up() 内に
 * `ALTER TABLE public.{name} ENABLE ROW LEVEL SECURITY` を必ず追加する
 * （Supabase の PostgREST 経由で外部公開されるのを防ぐ。policy は作らず default deny）。
 *
 * 本テストは RefreshDatabase が test-postgres に流した全 migration 後のスキーマを検査し、
 * RLS 未有効の public テーブルがあれば fail させる。
 *
 * 注: GRANT (authenticated / service_role) は Supabase 固有ロールに依存し、素の
 * test-postgres には当該ロールが存在しないため migration 側で role-existence ガードされ
 * skip される。よって GRANT は runtime 検査不可。新規 migration の GRANT 漏れは
 * rls-grant-audit スキル（PR レビュー時）でカバーする方針。
 */
class RlsGrantAuditTest extends PgsqlTestCase
{
    /**
     * Laravel フレームワークが作成し、RLS ルールの対象外とするテーブル。
     * （テナントデータを持たず、PostgREST 経由で公開されることもない基盤テーブル）
     */
    private const FRAMEWORK_TABLES = [
        'migrations',
        'password_reset_tokens',
        'password_resets',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'personal_access_tokens',
    ];

    /** public スキーマの base table 名 => relrowsecurity(bool) のマップ */
    private function publicTableRlsMap(): array
    {
        $rows = DB::select(<<<SQL
            SELECT c.relname, c.relrowsecurity
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind = 'r'
            ORDER BY c.relname
        SQL);

        $map = [];
        foreach ($rows as $r) {
            $map[$r->relname] = (bool) $r->relrowsecurity;
        }
        return $map;
    }

    public function test_all_public_tables_have_rls_enabled(): void
    {
        $map = $this->publicTableRlsMap();

        $checked = 0;
        $missing = [];
        foreach ($map as $table => $rls) {
            if (in_array($table, self::FRAMEWORK_TABLES, true)) {
                continue;
            }
            $checked++;
            if (!$rls) {
                $missing[] = $table;
            }
        }

        // vacuous pass 防止: 実アプリのテーブルを十分検査できていることを保証する。
        // （migration 未実行や検査クエリ破損で空振り合格になるのを防ぐ）
        $this->assertGreaterThan(
            20,
            $checked,
            "検査対象テーブルが少なすぎる（migration 未実行 or 検査クエリ破損の疑い）: {$checked} 件",
        );

        $this->assertSame(
            [],
            $missing,
            "RLS 未有効の public テーブル（新規テーブル migration の "
            . "'ALTER TABLE public.{name} ENABLE ROW LEVEL SECURITY' 漏れの疑い）: "
            . implode(', ', $missing),
        );
    }

    public function test_audit_query_detects_a_table_without_rls(): void
    {
        // ポジティブコントロール: RLS 無効テーブルを作れば検出ロジックが必ず拾うことを保証。
        // 検査クエリが将来壊れて常時 pass（vacuous）になるのを防ぐ。
        // RefreshDatabase のトランザクション内で作成 → ロールバックされる。
        DB::statement('CREATE TABLE public._rls_probe (id int)');
        try {
            $map = $this->publicTableRlsMap();

            $this->assertArrayHasKey('_rls_probe', $map, '検査クエリが新規テーブルを拾えていない');
            $this->assertFalse($map['_rls_probe'], '検査クエリが RLS 状態を正しく読めていない');
        } finally {
            DB::statement('DROP TABLE IF EXISTS public._rls_probe');
        }
    }
}
