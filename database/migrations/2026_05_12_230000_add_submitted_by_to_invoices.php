<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices.submitted_by を追加
 *
 * 承認申請者を記録するためのカラム。submitForApproval 時に
 * 現在ログインユーザーを保存し、approve 成立時に申請者本人へ
 * バッジ通知できるようにする。
 *
 * 既存データは NULL のままで運用可（過去申請は通知対象外）。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('submitted_by')
                ->nullable()
                ->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('submitted_by');
        });
    }
};
