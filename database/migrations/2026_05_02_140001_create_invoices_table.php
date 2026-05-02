<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoices テーブル新規作成（Phase C）
 *
 * 請求書本体。案件×月で1請求書を発行する。
 * 採番フォーマット: INV-[customer.invoice_code]-YYYY-MM-NNNNN
 *   NNNNN は customer × year_month 内で 00001 から連番
 *
 * 顧客名/住所/技術者名/請求元情報は発行時点のスナップショットを保持。
 * 顧客マスタ更新の影響を受けない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('customer_id');
            $table->char('year_month', 7)->comment('対象年月（YYYY-MM）');

            $table->string('invoice_number', 60)->unique()
                ->comment('INV-[code]-YYYY-MM-NNNNN');

            $table->date('issued_date')->comment('発行日');
            $table->date('due_date')->nullable()->comment('支払期限');

            // 金額（明細から再計算した結果のキャッシュ）
            $table->decimal('subtotal', 12, 2)->default(0)->comment('税抜合計');
            $table->decimal('tax', 12, 2)->default(0)->comment('消費税合計');
            $table->decimal('total', 12, 2)->default(0)->comment('税込合計');

            $table->enum('status', ['draft', 'issued'])->default('draft');
            $table->string('pdf_path', 500)->nullable()->comment('Supabase Storage パス');

            $table->text('notes')->nullable();

            // ── スナップショット ───────────────────────────
            $table->string('customer_name_snapshot', 255)->nullable();
            $table->text('customer_address_snapshot')->nullable();
            $table->string('engineer_name_snapshot', 255)->nullable();

            $table->string('issuer_name_snapshot', 255)->nullable();
            $table->string('issuer_postal_code_snapshot', 20)->nullable();
            $table->text('issuer_address_snapshot')->nullable();
            $table->string('issuer_tel_snapshot', 50)->nullable();
            $table->string('issuer_invoice_number_snapshot', 30)->nullable()
                ->comment('適格請求書発行事業者登録番号 (T+13桁)');
            $table->string('issuer_bank_snapshot', 500)->nullable()
                ->comment('銀行/支店/種別/口座番号/名義をまとめたテキスト');

            $table->timestamps();

            $table->foreign('deal_id')->references('id')->on('deals')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');

            $table->index(['tenant_id', 'year_month'], 'idx_invoices_tenant_month');
            $table->index(['tenant_id', 'customer_id', 'year_month'], 'idx_invoices_tenant_cust_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
