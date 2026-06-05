<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 月別売上集計の明細テーブル (docs/460)。
 *
 * 設計判断 (2026-06-05 確定):
 *  - 月の判定: 契約期間ベース。contract_period_start〜end が対象月に重なる SES案件を計上。
 *  - 月またぎ: 月単位で粗計上 (按分なし)。1日でも重なれば全額をその月に計上するため、
 *    複数月にまたがる契約は各月に同額の明細行を持つ。
 *  - 粒度: 売上 (revenue=income_amount) + 仕入 (cost=billing_plus_29) + 利益 (profit)。
 *  - 真実のソースはこの明細。ダッシュボードのサマリは (tenant, year, month) で SUM して読む。
 *
 * (tenant_id, year, month, ses_contract_id) でユニーク。再集計は当月行を delete→insert。
 *
 * RLS: フロントは authenticated 経由 (supabase-js/Realtime) で読まず、Laravel API
 *      (DashboardController / MonthlySalesController) 経由でのみ読むため authenticated GRANT は不要。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_sales_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('ses_contract_id')->constrained()->onDelete('cascade');
            $table->smallInteger('year');
            $table->smallInteger('month'); // 1-12
            // engineer / project (ses_contracts.category のスナップショット。内訳表示用)
            $table->string('category', 20)->nullable();
            $table->decimal('revenue', 12, 2)->default(0); // income_amount (顧客請求額・売上)
            $table->decimal('cost', 12, 2)->default(0);    // billing_plus_29 (技術者支払額・仕入)
            $table->decimal('profit', 12, 2)->default(0);  // profit (利益)
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'year', 'month', 'ses_contract_id'], 'monthly_sales_details_unique');
            // サマリ集計用 (tenant + 期間の範囲検索)
            $table->index(['tenant_id', 'year', 'month'], 'monthly_sales_details_tenant_period_idx');
        });

        // RLS / GRANT (CLAUDE.md ルール準拠)。sqlite テストではスキップ
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE public.monthly_sales_details ENABLE ROW LEVEL SECURITY');
        // test-postgres は service_role を持たないためガード
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'service_role'")) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON public.monthly_sales_details TO service_role');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_sales_details');
    }
};
