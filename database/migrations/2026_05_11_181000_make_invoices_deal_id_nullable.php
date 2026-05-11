<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices.deal_id を nullable に変更
 *
 * 見積書(estimate)・注文書(purchase_order) は勤務表非依存で発行可能なため
 * deal_id を持たないケースがある。元の create migration では NOT NULL になっていたため
 * 制約を緩める。
 *
 * 既存の請求書(invoice)行は引き続き deal_id を持つため、データ整合性に影響なし。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('deal_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // ロールバック時は NOT NULL に戻さない（estimate/purchase_order 行が壊れるため）。
        // 必要であればデータクレンジング後に手動で戻すこと。
    }
};
