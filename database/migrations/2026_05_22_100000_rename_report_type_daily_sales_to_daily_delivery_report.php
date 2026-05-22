<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * report_recipients.report_type の値を 'daily_sales' → 'daily_delivery_report' へリネーム
 *
 * 命名修正: 中身は売上レポートではなく「日次配信レポート (前日の配信・提案実績)」
 * のため、コマンド名 / クラス名と合わせて DB 値も移行する。
 *
 *  - 既存レコードの値を一括 UPDATE
 *  - カラムのデフォルト値を更新
 *  - カラム COMMENT を更新
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('report_recipients')
            ->where('report_type', 'daily_sales')
            ->update(['report_type' => 'daily_delivery_report']);

        DB::statement("ALTER TABLE public.report_recipients ALTER COLUMN report_type SET DEFAULT 'daily_delivery_report'");
        DB::statement("COMMENT ON COLUMN public.report_recipients.report_type IS 'レポート種別 (daily_delivery_report / weekly / alert 等)'");
    }

    public function down(): void
    {
        DB::table('report_recipients')
            ->where('report_type', 'daily_delivery_report')
            ->update(['report_type' => 'daily_sales']);

        DB::statement("ALTER TABLE public.report_recipients ALTER COLUMN report_type SET DEFAULT 'daily_sales'");
        DB::statement("COMMENT ON COLUMN public.report_recipients.report_type IS 'レポート種別 (daily_sales / weekly / alert 等)'");
    }
};
