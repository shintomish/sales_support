<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenants に発行元の英文情報を追加
 *
 * Refinitiv 請求書（vendor_metadata あり）等の英文 PDF 出力に使用。
 * 日本語の住所/銀行情報とは別フィールドとして保持。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_issuer_name_en', 255)->nullable()->after('invoice_issuer_invoice_number');
            $table->text('invoice_issuer_address_en')->nullable()->after('invoice_issuer_name_en');
            $table->string('invoice_issuer_email', 255)->nullable()->after('invoice_issuer_address_en');
            $table->text('invoice_issuer_bank_details_en')->nullable()->after('invoice_issuer_email');
            $table->string('invoice_issuer_bank_account_holder_en', 255)->nullable()->after('invoice_issuer_bank_details_en');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_issuer_name_en',
                'invoice_issuer_address_en',
                'invoice_issuer_email',
                'invoice_issuer_bank_details_en',
                'invoice_issuer_bank_account_holder_en',
            ]);
        });
    }
};
