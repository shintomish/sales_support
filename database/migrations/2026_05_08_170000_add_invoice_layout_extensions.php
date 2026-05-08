<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 請求書テンプレ強化（Sick サンプル準拠 / 2026-05-08）
 *
 *  - delivery_items_text     : 納品物（既定: 作業報告書）
 *  - transportation_note_text: 業務交通費 説明（既定: お客様指示の基、移動が発生した場合は別途実費にてご請求）
 *  - 精算条件スナップショット: 超過控除セクションのヘッダ表記用
 *
 *  invoice_lines.is_expense : 業務交通費(非課税) の経費フラグ。経費合計を「経費」行として表示
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('delivery_items_text', 255)->nullable()->after('work_location')
                ->comment('納品物（既定: 作業報告書）');
            $table->text('transportation_note_text')->nullable()->after('delivery_items_text')
                ->comment('業務交通費 説明文');

            $table->integer('settlement_unit_minutes_snapshot')->nullable()->after('issuer_bank_snapshot');
            $table->decimal('client_deduction_hours_snapshot', 6, 2)->nullable()->after('settlement_unit_minutes_snapshot');
            $table->decimal('client_overtime_hours_snapshot', 6, 2)->nullable()->after('client_deduction_hours_snapshot');
            $table->decimal('client_deduction_unit_price_snapshot', 12, 2)->nullable()->after('client_overtime_hours_snapshot');
            $table->decimal('client_overtime_unit_price_snapshot', 12, 2)->nullable()->after('client_deduction_unit_price_snapshot');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->boolean('is_expense')->default(false)->after('amount')
                ->comment('true=経費(非課税)。請求書フッターの「経費」合計に集計');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_items_text',
                'transportation_note_text',
                'settlement_unit_minutes_snapshot',
                'client_deduction_hours_snapshot',
                'client_overtime_hours_snapshot',
                'client_deduction_unit_price_snapshot',
                'client_overtime_unit_price_snapshot',
            ]);
        });
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('is_expense');
        });
    }
};
