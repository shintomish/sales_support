<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenants にメール送信テンプレート（見積書 / 注文書）を追加
 *
 * 既存の invoice_email_*_template と並び、doc_type ごとに件名・本文を切替できるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('estimate_email_subject_template', 500)->nullable()->after('invoice_email_body_template');
            $table->text('estimate_email_body_template')->nullable()->after('estimate_email_subject_template');
            $table->string('purchase_order_email_subject_template', 500)->nullable()->after('estimate_email_body_template');
            $table->text('purchase_order_email_body_template')->nullable()->after('purchase_order_email_subject_template');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'estimate_email_subject_template',
                'estimate_email_body_template',
                'purchase_order_email_subject_template',
                'purchase_order_email_body_template',
            ]);
        });
    }
};
