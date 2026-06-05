<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * メール一覧を「到着(取込)時刻」順で表示するための created_at index。
 *
 * 背景: Kagoya の配送遅延(キュー滞留・最大数時間)で received_at(=Date ヘッダ=送信時刻)が
 * 実際の到着より大きく古くなり、営業から見ると一覧が webmail より古く見える問題。
 * 一覧の並びを created_at(=取込時刻≒到着) に変更する (EmailController::index)。
 * received_at の意味 (スコア/既読/スレッド基準) は変えない。
 *
 * created_at は INSERT 専用 (更新されない) ため、is_read の HOT 更新を阻害しない
 * (HOT 最適化と両立)。INSERT 時の btree 追加コストのみ。
 * 既存の received_at 複合 index に対応する 2 本を作成。
 *
 * 本番テーブル (約20万行・高頻度 insert) のため CONCURRENTLY。transaction 不可。
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('SET statement_timeout = 0');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_tenant_created_at_idx '
            . 'ON public.emails USING btree (tenant_id, created_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_tenant_category_created_at_idx '
            . 'ON public.emails USING btree (tenant_id, category, created_at DESC)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        DB::statement('SET statement_timeout = 0');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_tenant_created_at_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_tenant_category_created_at_idx');
    }
};
