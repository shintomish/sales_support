<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * emails.body_text の GIN pg_trgm index を撤去。
 *
 * 経緯:
 *   2026-05-25 に追加 (2026_05_25_100000_add_trgm_index_to_emails_body_text)。
 *   2026-06-02 時点で本番調査:
 *     - index size: 368MB (emails テーブル全 index 合計の 90%)
 *     - 過去 31日の nginx log 集計: search_body=1 リクエスト = 69件 (≒2.2件/日)
 *     - 直近 7日: 0件
 *     - 利用が低い割に書込パス (取込/再分類) で常時メンテコストが発生
 *
 * 判断:
 *   将来 ts_vector / Meilisearch 等で本格対応するなら別設計が必要。
 *   現状は subject/from のみ index 検索で十分と判断し、index を撤去する。
 *   body_text ILIKE 自体は 3 controllers で残存 (Seq Scan フォールバック)。
 *   暴走防止のため文字数ガードを 3 → 5 に引き上げ済 (本コミット内)。
 *
 * 注意:
 *   CONCURRENTLY DROP は transaction 内で実行できないため $withinTransaction = false。
 *   Session Pooler の statement_timeout=2min を一時無効化 (368MB の DROP に余裕を持たせる)。
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('SET statement_timeout = 0');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_body_text_trgm_idx');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('SET statement_timeout = 0');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_body_text_trgm_idx ON public.emails USING GIN (body_text gin_trgm_ops)');
    }
};
