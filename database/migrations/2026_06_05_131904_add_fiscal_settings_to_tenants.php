<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * テナントに決算情報を追加 (docs/460 月別売上の年度ビュー用)。
 *
 * - fiscal_year_end_month: 決算月 (1-12)。例 9 = 9月決算 → 年度は 10月〜翌9月。
 * - first_period_fiscal_year: 第1期の年度。期 = 年度 - first_period_fiscal_year + 1。
 *   例 2011 なら 2026年度 = 16期。
 *
 * 「年度」は決算月で終わる会計年度を、その終了月の暦年で表す
 * (9月決算なら 2025-10〜2026-09 = 2026年度)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->smallInteger('fiscal_year_end_month')->nullable()->after('ses_enabled');
            $table->smallInteger('first_period_fiscal_year')->nullable()->after('fiscal_year_end_month');
        });

        // 自社テナント (aizen / tenant_id=1) を 9月決算・第1期=2011年度 で初期化。
        // 他テナントは null のまま (フロントは未設定なら暦年フォールバック)。
        DB::table('tenants')->where('id', 1)->update([
            'fiscal_year_end_month'    => 9,
            'first_period_fiscal_year' => 2011,
        ]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['fiscal_year_end_month', 'first_period_fiscal_year']);
        });
    }
};
