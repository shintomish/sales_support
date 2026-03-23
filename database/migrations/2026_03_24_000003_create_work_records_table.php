<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_recordsテーブル新規作成
 *
 * SES案件の月次勤怠・請求情報を管理する。
 * deal_id + year_month で一意（1案件につき1ヶ月1レコード）。
 *
 * 対応するExcel列（1行が1ヶ月分のスナップショット）:
 *   ── 勤務表 ───────────────────────────────────────────
 *   - timesheet_received_date : 受領日    [col 39]
 *   - transportation_fee      : 交通費    [col 40]
 *   - absence_days            : 欠勤      [col 41]
 *   - paid_leave_days         : 有給      [col 42]
 *
 *   ── 請求書 ───────────────────────────────────────────
 *   - invoice_exists          : 有無      [col 43]（有 / 無）
 *   - invoice_received_date   : 受領日    [col 44]
 *
 *   ── 備考 ─────────────────────────────────────────────
 *   - notes                   : 特記事項  [col 45]
 *
 * 注意:
 *   Excelは1案件につき1行で「最新月」のみを管理しているが、
 *   本テーブルは月ごとに履歴を蓄積する設計とする。
 *   インポート時は contract_period_end の月を year_month として使用する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index()
                ->comment('テナントID（多テナント分離用）');
            $table->unsignedBigInteger('deal_id')
                ->comment('dealsテーブルへの外部キー');

            // 対象年月（YYYY-MM 形式。例: 2026-03）
            $table->char('year_month', 7)
                ->comment('対象年月（YYYY-MM）');

            // ── 勤務表 ────────────────────────────────
            $table->date('timesheet_received_date')->nullable()
                ->comment('勤務表 受領日');
            $table->decimal('transportation_fee', 10, 2)->nullable()
                ->comment('交通費（円）');
            $table->decimal('absence_days', 4, 1)->nullable()
                ->comment('欠勤日数');
            $table->decimal('paid_leave_days', 4, 1)->nullable()
                ->comment('有給取得日数');

            // ── 請求書 ────────────────────────────────
            $table->boolean('invoice_exists')->nullable()
                ->comment('請求書 有無（true=有, false=無, null=未確認）');
            $table->date('invoice_received_date')->nullable()
                ->comment('請求書 受領日');

            // ── その他 ────────────────────────────────
            $table->text('notes')->nullable()
                ->comment('特記事項（フリーテキスト）');

            $table->timestamps();

            // ── 外部キー & インデックス ──────────────
            $table->foreign('deal_id')
                ->references('id')
                ->on('deals')
                ->onDelete('cascade');

            // 1案件×1ヶ月でユニーク
            $table->unique(['deal_id', 'year_month'], 'uniq_work_records_deal_month');

            $table->index(['tenant_id', 'year_month'], 'idx_work_records_tenant_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_records');
    }
};
