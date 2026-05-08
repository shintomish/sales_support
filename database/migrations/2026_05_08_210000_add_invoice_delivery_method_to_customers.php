<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 顧客に「請求書送付方法」を追加
 *
 *  - mail : 電子メールのみ
 *  - post : 郵送のみ（既定）
 *  - both : メール + 郵送
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('invoice_delivery_method', 10)->default('post')->after('payment_site');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('invoice_delivery_method');
        });
    }
};
