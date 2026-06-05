<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * emails.arrived_at = IMAP INTERNALDATE (Kagoya メールボックス着信時刻 = webmail 表示と一致)。
 *
 * 背景: received_at は Date ヘッダ(送信時刻)で、Kagoya の配送遅延により実到着より古い。
 * created_at(取込時刻)はポーリング(5分)ラグを含む。webmail と完全一致する到着時刻が欲しいので
 * INTERNALDATE を専用カラムに保存し、一覧の並び/「受信」表示に使う。
 *
 * 手順 (本番 hot テーブル・約20万行):
 *  1) カラム追加 (nullable・メタデータのみで即時)
 *  2) 既存行を arrived_at = created_at で backfill (INTERNALDATE 未保存のため取込時刻で代用)。
 *     index 作成前なので arrived_at 更新は HOT で軽量。LIMIT バッチで statement_timeout 回避。
 *  3) arrived_at 複合 index を CONCURRENTLY 作成 (received_at 系と同形)
 *  4) 先行で追加した created_at 並び替え index を撤去 (arrived_at 並びへ移行・dead index 化を防ぐ)
 *
 * CONCURRENTLY を含むため $withinTransaction = false。
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // 1) カラム追加 (sqlite テスト含め portable)
        if (!Schema::hasColumn('emails', 'arrived_at')) {
            Schema::table('emails', function (Blueprint $table) {
                $table->timestamp('arrived_at')->nullable()->after('received_at');
            });
        }

        if (DB::connection()->getDriverName() !== 'pgsql') return;

        DB::statement('SET statement_timeout = 0');

        // 2) backfill (index 作成前 = HOT・LIMIT バッチ)
        do {
            $n = DB::update(
                'UPDATE public.emails SET arrived_at = created_at '
                . 'WHERE id IN (SELECT id FROM public.emails WHERE arrived_at IS NULL LIMIT 5000)'
            );
        } while ($n > 0);

        // 3) arrived_at 並び替え index (received_at 系と同形)
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_tenant_arrived_at_idx '
            . 'ON public.emails USING btree (tenant_id, arrived_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_tenant_category_arrived_at_idx '
            . 'ON public.emails USING btree (tenant_id, category, arrived_at DESC)');

        // 4) created_at 並び替え index を撤去 (arrived_at へ移行)
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_tenant_created_at_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_tenant_category_created_at_idx');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET statement_timeout = 0');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_tenant_created_at_idx '
                . 'ON public.emails USING btree (tenant_id, created_at DESC)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS emails_tenant_category_created_at_idx '
                . 'ON public.emails USING btree (tenant_id, category, created_at DESC)');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_tenant_arrived_at_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.emails_tenant_category_arrived_at_idx');
        }
        if (Schema::hasColumn('emails', 'arrived_at')) {
            Schema::table('emails', function (Blueprint $table) {
                $table->dropColumn('arrived_at');
            });
        }
    }
};
