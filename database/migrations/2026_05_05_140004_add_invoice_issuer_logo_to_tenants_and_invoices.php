<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenants にロゴ画像パス、invoices にスナップショットを追加（2026-05-05）
 *
 * 請求書 PDF 右上に表示するロゴ画像を、テナント単位で設定できるようにする。
 * 既存の env(INVOICE_LOGO_PATH) によるグローバル指定は、テナント側未設定時の
 * フォールバックとして残す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_issuer_logo_path', 500)->nullable()
                ->after('invoice_issuer_fax')
                ->comment('請求書発行元 ロゴ画像 Supabase Storage 公開URL');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('issuer_logo_snapshot', 500)->nullable()
                ->after('issuer_fax_snapshot')
                ->comment('請求書発行元 ロゴ画像 URL スナップショット');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('invoice_issuer_logo_path');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('issuer_logo_snapshot');
        });
    }
};
