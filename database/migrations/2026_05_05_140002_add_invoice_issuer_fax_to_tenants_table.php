<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenants に請求書発行元のFAX番号カラムを追加（2026-05-05）
 *
 * 新請求書PDFレイアウトでヘッダーに FAX 番号を表示するため。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_issuer_fax', 50)->nullable()
                ->after('invoice_issuer_tel')
                ->comment('請求書発行元 FAX番号');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('invoice_issuer_fax');
        });
    }
};
