<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ses_contractsテーブル新規作成
 *
 * SES案件固有の精算条件・金額・契約期間を管理する。
 * deals に 1:1 で紐付く（deal_id が外部キー）。
 *
 * 対応するExcel列:
 *   ── 金額系 ──────────────────────────────────────────
 *   - income_amount        : 入金        [col 15]
 *   - billing_plus_22      : 支払+22%    [col 16]
 *   - billing_plus_29      : 支払+29%    [col 17]
 *   - sales_support_payee  : 営業支援費支払先 [col 18]
 *   - sales_support_fee    : 営業支援費  [col 19]
 *   - adjustment_amount    : 調整金額    [col 20]
 *   - profit               : 利益        [col 21]
 *   - profit_rate_29       : 利益/29%    [col 22]
 *
 *   ── 顧客側精算条件（顧客への請求基準）─────────────────
 *   - client_deduction_unit_price : 控除単価  [col 23]
 *   - client_deduction_hours      : 控除時間  [col 24]
 *   - client_overtime_unit_price  : 超過単価  [col 25]
 *   - client_overtime_hours       : 超過時間  [col 26]
 *   - settlement_unit_minutes     : 精算単位(分) [col 27]
 *   - payment_site                : 入金サイト(日) [col 28]
 *
 *   ── 仕入れ側精算条件（技術者への支払い基準）────────────
 *   - vendor_deduction_unit_price : 控除単価  [col 29]
 *   - vendor_deduction_hours      : 控除時間  [col 30]
 *   - vendor_overtime_unit_price  : 超過単価  [col 31]
 *   - vendor_overtime_hours       : 超過時間  [col 32]
 *   - vendor_payment_site         : 支払サイト(日) [col 33]
 *
 *   ── 契約期間 ─────────────────────────────────────────
 *   - contract_start         : 契約開始       [col 34]
 *   - contract_period_start  : 契約期間開始   [col 35]
 *   - contract_period_end    : 契約期間終了   [col 36]
 *   - affiliation_period_end : 期間末（所属） [col 37]（文字列。"自動"等の値あり）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ses_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');
            $table->unsignedBigInteger('deal_id')->unique()
                ->comment('dealsテーブルへの外部キー（1:1）');

            // ── 金額系 ──────────────────────────────────
            $table->decimal('income_amount', 12, 2)->nullable()
                ->comment('入金額（顧客からの請求額）');
            $table->decimal('billing_plus_22', 12, 2)->nullable()
                ->comment('支払額（技術者給料+22%）');
            $table->decimal('billing_plus_29', 12, 2)->nullable()
                ->comment('支払額（技術者給料+29%）');
            $table->string('sales_support_payee', 200)->nullable()
                ->comment('営業支援費支払先');
            $table->decimal('sales_support_fee', 12, 2)->nullable()
                ->comment('営業支援費');
            $table->decimal('adjustment_amount', 12, 2)->nullable()
                ->comment('調整金額');
            $table->decimal('profit', 12, 2)->nullable()
                ->comment('利益（income_amount - billing - fee + adjustment）');
            $table->decimal('profit_rate_29', 12, 2)->nullable()
                ->comment('利益/29%（利益の内29%の割合で試算した額）');

            // ── 顧客側精算条件 ───────────────────────────
            // Excelで '-' が入っているケースが多いため nullable
            $table->decimal('client_deduction_unit_price', 10, 2)->nullable()
                ->comment('顧客側 控除単価（下限時間を下回った場合の控除単価）');
            $table->decimal('client_deduction_hours', 6, 2)->nullable()
                ->comment('顧客側 控除時間（精算下限時間）');
            $table->decimal('client_overtime_unit_price', 10, 2)->nullable()
                ->comment('顧客側 超過単価（上限時間を超えた場合の追加単価）');
            $table->decimal('client_overtime_hours', 6, 2)->nullable()
                ->comment('顧客側 超過時間（精算上限時間）');
            $table->smallInteger('settlement_unit_minutes')->nullable()
                ->comment('精算単位（分）: 15/30等。15分単位で切り捨て等の計算に使用');
            $table->smallInteger('payment_site')->nullable()
                ->comment('入金サイト（日数）: 月末締め翌40日払い → 40');

            // ── 仕入れ側精算条件 ─────────────────────────
            $table->decimal('vendor_deduction_unit_price', 10, 2)->nullable()
                ->comment('仕入れ側 控除単価');
            $table->decimal('vendor_deduction_hours', 6, 2)->nullable()
                ->comment('仕入れ側 控除時間');
            $table->decimal('vendor_overtime_unit_price', 10, 2)->nullable()
                ->comment('仕入れ側 超過単価');
            $table->decimal('vendor_overtime_hours', 6, 2)->nullable()
                ->comment('仕入れ側 超過時間');
            $table->smallInteger('vendor_payment_site')->nullable()
                ->comment('支払サイト（日数）');

            // ── 契約期間 ─────────────────────────────────
            $table->date('contract_start')->nullable()
                ->comment('契約開始日（最初の契約開始。更新をまたいでも変わらない）');
            $table->date('contract_period_start')->nullable()
                ->comment('現在の契約期間 開始日');
            $table->date('contract_period_end')->nullable()
                ->comment('現在の契約期間 終了日（アラート基準日）');
            $table->string('affiliation_period_end', 50)->nullable()
                ->comment('期間末（所属）: "自動" or 日付文字列。Excelそのままの値を保持');

            $table->timestamps();

            // ── 外部キー & インデックス ──────────────────
            $table->foreign('deal_id')
                ->references('id')
                ->on('deals')
                ->onDelete('cascade');

            $table->index(['tenant_id', 'contract_period_end'], 'idx_ses_contracts_tenant_period_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ses_contracts');
    }
};
