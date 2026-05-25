<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * emails.body_text に GIN pg_trgm index を追加。
 *
 * 2026-05-25 本番 nginx access log 解析で /api/v1/emails?search_body=1 の本文検索が
 * 最大 98.246 秒 (rt) を記録。emails 76,233 行 / 373MB / body_text 平均1,523byte に対する
 * leading-wildcard ILIKE ('%search%') が Seq Scan になっていたため。
 *
 * 同パターン (`body_text ilike '%search%'`) は以下 3 箇所で使用されており、
 * 1 本の index で 3 つの API すべてに効く:
 *   - EmailController::index           (GET /api/v1/emails)
 *   - ProjectMailController::index     (GET /api/v1/project-mails)
 *   - EngineerMailController::index    (GET /api/v1/engineer-mails)
 *
 * 設計判断:
 * - 76,233 行 + 同時書き込みを止めないため CONCURRENTLY を使用。
 *   CONCURRENTLY は transaction 内で実行できないため $withinTransaction = false。
 * - pg_trgm は Supabase 標準サポート拡張。down() では EXTENSION は drop しない (他で使う可能性)。
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // 既存 index が INVALID 状態 (前回 CONCURRENTLY 中断) ならリカバリ DROP。
        // CONCURRENTLY 中に接続切断等で失敗すると INVALID な物理ファイル (本案件で 132MB) が
        // 残り、IF NOT EXISTS で再実行しても skip されて壊れたまま放置される実害があった。
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM pg_class c
                    JOIN pg_index i ON i.indexrelid = c.oid
                    WHERE c.relname = 'emails_body_text_trgm_idx'
                      AND NOT i.indisvalid
                ) THEN
                    EXECUTE 'DROP INDEX IF EXISTS public.emails_body_text_trgm_idx';
                END IF;
            END
            $$
        SQL);

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_body_text_trgm_idx ON public.emails USING GIN (body_text gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_body_text_trgm_idx');
    }
};
