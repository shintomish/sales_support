<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * project_mail_sources / engineer_mail_sources に arrived_at を追加。
 *
 * 背景: emails.arrived_at (IMAP INTERNALDATE = Kagoya 着信時刻) を 2026-06-05 に追加し、
 * /emails 一覧の並び/「受信」表示を arrived_at へ移行済み (2026_06_05_160042)。
 * しかし /project-mails・/engineer-mails 一覧は PMS/EMS.received_at (= Date ヘッダ = 送信時刻) で
 * 並び・表示しており、Kagoya 配送遅延(~数h)で「着信は新しいのに送信時刻が古く一覧で埋もれる」
 * 鮮度問題が残っていた。received_at と同様に arrived_at を非正規化して並び/表示を着信時刻に揃える。
 *
 * 手順 (本番 hot テーブル・PMS ~4.3万 / EMS ~8.5万行・2026_06_05_160042 と同形):
 *  1) カラム追加 (nullable・メタデータのみで即時)
 *  2) emails.arrived_at から LIMIT バッチ backfill (index 作成前 = HOT・statement_timeout 回避)。
 *     emails が cleanup 済で join できない孤児行は received_at をフォールバック。
 *  3) (tenant_id, arrived_at DESC) index を CONCURRENTLY 作成 (received_at index と同形)
 *  4) 並び替えを arrived_at へ移行するため received_at index を撤去 (dead index 化を防ぐ)
 *
 * CONCURRENTLY を含むため $withinTransaction = false。
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private array $tables = ['project_mail_sources', 'engineer_mail_sources'];

    public function up(): void
    {
        // 1) カラム追加 (sqlite テスト含め portable)
        foreach ($this->tables as $t) {
            if (!Schema::hasColumn($t, 'arrived_at')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->timestamp('arrived_at')->nullable()->after('received_at');
                });
            }
        }

        if (DB::connection()->getDriverName() !== 'pgsql') return;

        DB::statement('SET statement_timeout = 0');

        foreach ($this->tables as $t) {
            // 2) backfill: emails.arrived_at をコピー (index 作成前 = HOT・LIMIT バッチ)
            do {
                $n = DB::update(
                    "UPDATE public.{$t} AS s SET arrived_at = e.arrived_at "
                    . "FROM public.emails e "
                    . "WHERE s.email_id = e.id "
                    . "AND s.id IN (SELECT id FROM public.{$t} WHERE arrived_at IS NULL LIMIT 5000)"
                );
            } while ($n > 0);

            // emails が cleanup 済で join できない孤児行は received_at をフォールバック
            DB::update("UPDATE public.{$t} SET arrived_at = received_at WHERE arrived_at IS NULL");

            // 3) arrived_at 並び替え index (received_at index と同形)
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$t}_tenant_id_arrived_at_index "
                . "ON public.{$t} USING btree (tenant_id, arrived_at DESC)");

            // 4) received_at index を撤去 (arrived_at 並びへ移行)
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS public.{$t}_tenant_id_received_at_index");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET statement_timeout = 0');
            foreach ($this->tables as $t) {
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$t}_tenant_id_received_at_index "
                    . "ON public.{$t} USING btree (tenant_id, received_at)");
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS public.{$t}_tenant_id_arrived_at_index");
            }
        }
        foreach ($this->tables as $t) {
            if (Schema::hasColumn($t, 'arrived_at')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->dropColumn('arrived_at');
                });
            }
        }
    }
};
