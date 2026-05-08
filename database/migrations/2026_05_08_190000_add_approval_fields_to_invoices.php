<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 請求書 承認フラグ（電子印 押印トリガ）
 *
 *  - approved      : 承認済みフラグ。true のとき PDF に電子印を押印
 *  - approved_at   : 承認日時
 *  - approved_by   : 承認したユーザー ID
 *
 * 承認は tenant_admin / super_admin のみが実行可能（コントローラ側で制御）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('approved')->default(false)->after('status');
            $table->timestamp('approved_at')->nullable()->after('approved');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['approved', 'approved_at', 'approved_by']);
        });
    }
};
