<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices テーブルに新請求書レイアウト用カラムを追加（2026-05-05）
 *
 * INV_Aizen 新仕様で必要となる入力欄。
 *  - order_number          (B) 注文No.
 *  - quote_number          (C) 見積No.
 *  - subject_name          (K) 件名（SES台帳の案件名から自動引用）
 *  - work_period_text      (L) 作業期間表記
 *  - work_location         (N) 作業場所
 *  - delivery_date_text    (E) 納期       既定: 御社ご指定日
 *  - delivery_place_text   (F) 納入場所   既定: 御社ご指定場所
 *  - payment_terms_text    (G) 支払期限文言（O 支払条件は本値から「現金」を除いて派生）
 *
 * (D) 登録番号は既存の issuer_invoice_number_snapshot を流用する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('order_number', 100)->nullable()->after('invoice_number')
                ->comment('注文No.（顧客側PO番号など。手入力）');
            $table->string('quote_number', 100)->nullable()->after('order_number')
                ->comment('見積No.（手入力）');
            $table->string('subject_name', 255)->nullable()->after('quote_number')
                ->comment('件名（SES案件名から自動引用、編集可）');
            $table->string('work_period_text', 100)->nullable()->after('subject_name')
                ->comment('作業期間表記（YYYY年M月D日～YYYY年M月D日）');
            $table->string('work_location', 255)->nullable()->after('work_period_text')
                ->comment('作業場所');
            $table->string('delivery_date_text', 100)->nullable()->after('work_location')
                ->comment('納期 文言（既定: 御社ご指定日）');
            $table->string('delivery_place_text', 100)->nullable()->after('delivery_date_text')
                ->comment('納入場所 文言（既定: 御社ご指定場所）');
            $table->string('payment_terms_text', 100)->nullable()->after('delivery_place_text')
                ->comment('支払期限 文言（例: 月末締め翌々月20日現金お支払）');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'order_number',
                'quote_number',
                'subject_name',
                'work_period_text',
                'work_location',
                'delivery_date_text',
                'delivery_place_text',
                'payment_terms_text',
            ]);
        });
    }
};
