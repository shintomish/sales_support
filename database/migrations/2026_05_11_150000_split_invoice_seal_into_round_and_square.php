<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 電子印を「丸印（請求書/注文書用）」「角印（見積書用）」の 2 種類に分割（2026-05-11）
 *
 *  - tenants.invoice_issuer_seal_path → invoice_issuer_round_seal_path にリネーム相当
 *  - tenants.invoice_issuer_square_seal_path を新規追加
 *  - invoices.issuer_seal_snapshot → issuer_round_seal_snapshot にリネーム相当
 *  - invoices.issuer_square_seal_snapshot を新規追加（請求書では未使用だが
 *    将来共通スキーマで利用するため確保）
 *
 * 既存データは round_*_path / round_*_snapshot 側にコピー → 旧カラムを drop。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_issuer_round_seal_path', 500)->nullable()->after('invoice_issuer_seal_path')
                ->comment('丸印（請求書・注文書用）Supabase Storage URL');
            $table->string('invoice_issuer_square_seal_path', 500)->nullable()->after('invoice_issuer_round_seal_path')
                ->comment('角印（見積書用）Supabase Storage URL');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('issuer_round_seal_snapshot', 500)->nullable()->after('issuer_seal_snapshot')
                ->comment('発行時の丸印 URL スナップショット');
            $table->string('issuer_square_seal_snapshot', 500)->nullable()->after('issuer_round_seal_snapshot')
                ->comment('発行時の角印 URL スナップショット（請求書では未使用）');
        });

        // 既存データを round 側にコピー
        DB::statement('UPDATE tenants SET invoice_issuer_round_seal_path = invoice_issuer_seal_path WHERE invoice_issuer_seal_path IS NOT NULL');
        DB::statement('UPDATE invoices SET issuer_round_seal_snapshot = issuer_seal_snapshot WHERE issuer_seal_snapshot IS NOT NULL');

        // 旧カラム削除
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('invoice_issuer_seal_path');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('issuer_seal_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_issuer_seal_path', 500)->nullable()->after('invoice_issuer_logo_path');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('issuer_seal_snapshot', 500)->nullable()->after('issuer_logo_snapshot');
        });

        // round 側に入れたデータを旧カラムに戻す
        DB::statement('UPDATE tenants SET invoice_issuer_seal_path = invoice_issuer_round_seal_path WHERE invoice_issuer_round_seal_path IS NOT NULL');
        DB::statement('UPDATE invoices SET issuer_seal_snapshot = issuer_round_seal_snapshot WHERE issuer_round_seal_snapshot IS NOT NULL');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['invoice_issuer_round_seal_path', 'invoice_issuer_square_seal_path']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['issuer_round_seal_snapshot', 'issuer_square_seal_snapshot']);
        });
    }
};
