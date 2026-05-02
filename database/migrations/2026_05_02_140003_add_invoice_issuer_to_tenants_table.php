<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenants テーブルに請求書発行元情報を追加（Phase C）
 *
 * 適格請求書発行事業者登録番号（T+13桁）含む。
 * 請求書発行時にスナップショットとして invoices テーブルへコピーされる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('invoice_issuer_name', 255)->nullable()->after('is_active');
            $table->string('invoice_issuer_postal_code', 20)->nullable()->after('invoice_issuer_name');
            $table->text('invoice_issuer_address')->nullable()->after('invoice_issuer_postal_code');
            $table->string('invoice_issuer_tel', 50)->nullable()->after('invoice_issuer_address');
            $table->string('invoice_issuer_invoice_number', 30)->nullable()->after('invoice_issuer_tel')
                ->comment('適格請求書発行事業者登録番号（T+13桁）');
            $table->string('invoice_issuer_bank_name', 100)->nullable()->after('invoice_issuer_invoice_number');
            $table->string('invoice_issuer_bank_branch', 100)->nullable()->after('invoice_issuer_bank_name');
            $table->string('invoice_issuer_bank_account_type', 20)->nullable()->after('invoice_issuer_bank_branch')
                ->comment('普通 / 当座');
            $table->string('invoice_issuer_bank_account_number', 30)->nullable()->after('invoice_issuer_bank_account_type');
            $table->string('invoice_issuer_bank_account_holder', 100)->nullable()->after('invoice_issuer_bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_issuer_name',
                'invoice_issuer_postal_code',
                'invoice_issuer_address',
                'invoice_issuer_tel',
                'invoice_issuer_invoice_number',
                'invoice_issuer_bank_name',
                'invoice_issuer_bank_branch',
                'invoice_issuer_bank_account_type',
                'invoice_issuer_bank_account_number',
                'invoice_issuer_bank_account_holder',
            ]);
        });
    }
};
