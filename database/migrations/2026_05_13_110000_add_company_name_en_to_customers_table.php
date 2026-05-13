<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customers テーブルに company_name_en を追加
 *  - company_name_en: 英文社名（英文見積書の宛先用）
 *
 * 入力は任意。空の場合は和文社名にフォールバックする。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('company_name_en', 255)->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('company_name_en');
        });
    }
};
