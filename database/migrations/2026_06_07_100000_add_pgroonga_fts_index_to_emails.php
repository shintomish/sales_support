<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * emails の本文検索を pgroonga 全文索引で高速化する。
 *
 * 旧 trgm GIN を撤去 (2026-06-02) して以降、「本文も検索」(body_text ILIKE) は Seq Scan で
 * 最大 20 秒かかっていた (2026-06-07 Sentry/pg_stat_statements で確認)。pgroonga (Groonga 由来・
 * 日本語に強い全文検索) の式索引を導入し、subject/from/from_name/body_text を連結した式に対する
 * `&@~` 検索を sub-second 化する (本番実測: no-match 3ms / 高頻度語 114ms)。
 *
 * 検索クエリ(EmailController::index)の連結式は、この index 式と完全一致させること。
 * 不一致だと index が使われず Seq Scan に落ちる。
 *
 * pgroonga 非対応の環境 (テスト用 postgres:17-alpine / sqlite) ではスキップする。
 * その環境ではアプリ側も config services.pgroonga.enabled=false で ILIKE フォールバックする。
 *
 * CONCURRENTLY を含むため $withinTransaction = false。
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'emails_pgroonga_fts_idx';
    private const EXPR = "(COALESCE(subject,'') || ' ' || COALESCE(from_address,'') || ' ' || COALESCE(from_name,'') || ' ' || COALESCE(body_text,''))";

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        // pgroonga が使えない環境 (テスト DB 等) はスキップ。
        if (!DB::selectOne("SELECT 1 AS ok FROM pg_available_extensions WHERE name = 'pgroonga'")) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pgroonga');
        DB::statement('SET statement_timeout = 0');
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX
            . ' ON public.emails USING pgroonga (' . self::EXPR . ')'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('SET statement_timeout = 0');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.' . self::INDEX);
        // 拡張は他で使う可能性があるため down では削除しない。
    }
};
