<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices に発行元FAX番号スナップショットを追加（2026-05-05）
 *
 * 既存の issuer_*_snapshot 群と同様、PDF 生成時点の値を保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('issuer_fax_snapshot', 50)->nullable()
                ->after('issuer_tel_snapshot')
                ->comment('請求書発行元 FAX番号 スナップショット');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('issuer_fax_snapshot');
        });
    }
};
