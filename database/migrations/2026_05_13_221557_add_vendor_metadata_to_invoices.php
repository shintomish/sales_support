<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices に vendor_metadata (JSONB) を追加
 *
 * Refinitiv の注文書 PDF から抽出した発注元固有のメタデータ
 * （申請者 / 申請番号 / Plant.ID / 分類コード 等）を格納する。
 * vendor_metadata が NULL でない請求書は Refinitiv 専用レイアウトで PDF 出力する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->jsonb('vendor_metadata')->nullable()->after('language');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('vendor_metadata');
        });
    }
};
