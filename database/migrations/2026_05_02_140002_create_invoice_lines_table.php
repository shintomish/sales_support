<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * invoice_lines テーブル新規作成（Phase C）
 *
 * 請求書の明細行。複数税率対応（10% / 8% / 0%）。
 *   amount = quantity × unit_price（税抜）
 *   tax_rate は行ごとに設定可能
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id');
            $table->integer('sort_order')->default(0);
            $table->string('description', 500);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->nullable()->comment('時間 / 件 / 日 等');
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 4)->default(0.10)
                ->comment('0.1000=10%, 0.0800=8%, 0.0000=非課税');
            $table->decimal('amount', 12, 2)->default(0)->comment('税抜小計（quantity × unit_price）');
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->index(['invoice_id', 'sort_order'], 'idx_invoice_lines_invoice_sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
