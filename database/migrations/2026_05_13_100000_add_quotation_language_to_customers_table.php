<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customers テーブルに quotation_language を追加
 *  - quotation_language: 英文見積書発行対象フラグ（true=英文対応顧客）
 *
 * 既存行は false（日本語のみ）で初期化する。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('quotation_language')->default(false)->after('invoice_delivery_method');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('quotation_language');
        });
    }
};
